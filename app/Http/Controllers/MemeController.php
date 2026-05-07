<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Meme;
use Illuminate\Http\Request;
use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;

class MemeController extends Controller
{
    private const SESSION_LIMIT  = 5;
    private const SESSION_KEY    = 'memes_created';
    private const SESSION_HASHES = 'memes_hashes';

    private function cloudinary(): Cloudinary
    {
        $config = new Configuration();
        $config->cloud->cloudName = env('CLOUDINARY_CLOUD_NAME');
        $config->cloud->apiKey    = env('CLOUDINARY_API_KEY');
        $config->cloud->apiSecret = env('CLOUDINARY_API_SECRET');
        $config->url->secure      = true;

        return new Cloudinary($config);
    }

    // ────────────────────────────────────────────────────────────────
    // GET /api/memes
    // ────────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $query = Meme::query()->latest();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('tags', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->paginate((int) $request->input('per_page', 12))
        );
    }

    // ────────────────────────────────────────────────────────────────
    // POST /api/generate
    // ────────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        // ── 1. Limite de session ──────────────────────────────────────
        $count = session(self::SESSION_KEY, 0);
        if ($count >= self::SESSION_LIMIT) {
            return response()->json([
                'error' => 'Session limit reached: you cannot save more than '
                         . self::SESSION_LIMIT . ' memes per session.',
            ], 429);
        }

        // ── 2. Validation ─────────────────────────────────────────────
        $request->validate([
            'name'        => 'required|string|max:20',
            'image_data'  => 'required|string',
            'top_text'    => 'nullable|string|max:20',
            'bottom_text' => 'nullable|string|max:20',
            'tags'        => 'nullable|string|max:20',
        ]);

        $topText    = trim($request->input('top_text', ''));
        $bottomText = trim($request->input('bottom_text', ''));

        if ($topText === '' && $bottomText === '') {
            return response()->json([
                'error' => 'At least one of Top Text or Bottom Text must be filled in.',
            ], 422);
        }

        // ── 3. Décodage de l'image ────────────────────────────────────
        $imageData = $request->input('image_data');
        if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,/', $imageData, $matches)) {
            return response()->json(['error' => 'Invalid image data.'], 422);
        }

        $rawData = base64_decode(substr($imageData, strpos($imageData, ',') + 1));

        // ── 4. Détection de doublon ───────────────────────────────────
        $imageHash = md5($rawData);
        $hashes    = session(self::SESSION_HASHES, []);

        if (in_array($imageHash, $hashes)) {
            return response()->json([
                'error' => 'This meme has already been saved in this session.',
            ], 409);
        }

        // ── 5. Upload vers Cloudinary ─────────────────────────────────
        try {
            $tmpPath = tempnam(sys_get_temp_dir(), 'meme_');
            file_put_contents($tmpPath, $rawData);

            $result = $this->cloudinary()->uploadApi()->upload($tmpPath, [
                'folder'        => 'memes',
                'resource_type' => 'image',
            ]);

            @unlink($tmpPath);

            $imageUrl = $result['secure_url'];
            $publicId = $result['public_id'];

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Image upload failed: ' . $e->getMessage(),
            ], 500);
        }

        // ── 6. Enregistrement en base ─────────────────────────────────
        Meme::create([
            'name'        => $request->input('name'),
            'image_url'   => $imageUrl,
            'public_id'   => $publicId,
            'top_text'    => $topText,
            'bottom_text' => $bottomText,
            'tags'        => $request->input('tags'),
            'session_id'  => session()->getId(),
        ]);

        // ── 7. Mise à jour session ────────────────────────────────────
        $hashes[] = $imageHash;
        session([
            self::SESSION_KEY    => $count + 1,
            self::SESSION_HASHES => $hashes,
        ]);

        return response()->json([
            'message'   => 'Meme saved successfully!',
            'meme_url'  => $imageUrl,
            'remaining' => self::SESSION_LIMIT - ($count + 1),
        ], 201);
    }

    // ────────────────────────────────────────────────────────────────
    // DELETE /api/deleteMeme
    // ────────────────────────────────────────────────────────────────
    public function destroy(Request $request)
    {
        $request->validate(['public_id' => 'required|string']);

        $meme = Meme::where('public_id', $request->input('public_id'))->first();

        if (!$meme) {
            return response()->json(['error' => 'Meme not found.'], 404);
        }

        try {
            $this->cloudinary()->uploadApi()->destroy($meme->public_id);
        } catch (\Exception $e) {
            // Continue même si Cloudinary échoue
        }

        $sessionId = $meme->session_id;
        $meme->delete();

        if ($sessionId) {
            $sessionPath = storage_path('framework/sessions/' . $sessionId);
            if (file_exists($sessionPath)) {
                $data = unserialize(file_get_contents($sessionPath));
                if (isset($data[self::SESSION_KEY]) && $data[self::SESSION_KEY] > 0) {
                    $data[self::SESSION_KEY]--;
                }
                $data[self::SESSION_HASHES] = [];
                file_put_contents($sessionPath, serialize($data));
            }
        }

        return response()->json(['message' => 'Meme deleted successfully.']);
    }
}