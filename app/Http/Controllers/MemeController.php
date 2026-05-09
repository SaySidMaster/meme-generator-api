<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Meme;
use Illuminate\Http\Request;
use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;
use Illuminate\Support\Str;

class MemeController extends Controller
{
    private const SESSION_LIMIT = 5;
    private const SESSION_KEY = 'memes_created';
    private const SESSION_HASHES = 'memes_hashes';

    private function cloudinary(): Cloudinary
    {
        $config = new Configuration();
        $config->cloud->cloudName = env('CLOUDINARY_CLOUD_NAME');
        $config->cloud->apiKey = env('CLOUDINARY_API_KEY');
        $config->cloud->apiSecret = env('CLOUDINARY_API_SECRET');
        $config->url->secure = true;

        return new Cloudinary($config);
    }

    /**
     * Retourne le session ID à utiliser :
     * - Si le client envoie X-Session-Id, on l'utilise (cas prod cross-origin)
     * - Sinon on génère un nouvel UUID et on l'envoie au client via le header
     */
    private function resolveSessionId(Request $request): string
    {
        $sessionId = $request->header('X-Session-Id');
        //affiche dans la console l'id de session utilisé
        
       if (!$sessionId) {
            // Générer un nouvel UUID si pas de session existante
            $sessionId = Str::uuid()->toString();
        }
        
        return $sessionId;
    }

    // GET /api/session
    public function sessionInfo(Request $request)
    {
        $sessionId = $this->resolveSessionId($request);
        $count = Meme::where('session_id', $sessionId)->count();

        return response()->json([
            'session_id' => $sessionId,
            'used' => $count,
            'limit' => self::SESSION_LIMIT,
            'remaining' => max(0, self::SESSION_LIMIT - $count),
        ]);
    }

    // GET /api/memes
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

    // POST /api/generate
    public function store(Request $request)
    {
        $sessionId = $this->resolveSessionId($request);
        $count = Meme::where('session_id', $sessionId)->count();

        if ($count >= self::SESSION_LIMIT) {
            return response()->json([
                'error' => 'Session limit reached: you cannot save more than '
                    . self::SESSION_LIMIT . ' memes per session.',
            ], 429);
        }

        $request->validate([
            'name' => 'required|string|max:20',
            'image_data' => 'required|string',
            'top_text' => 'nullable|string|max:20',
            'bottom_text' => 'nullable|string|max:20',
            'tags' => 'nullable|string|max:20',
        ]);

        $topText = trim($request->input('top_text', ''));
        $bottomText = trim($request->input('bottom_text', ''));

        if ($topText === '' && $bottomText === '') {
            return response()->json([
                'error' => 'At least one of Top Text or Bottom Text must be filled in.',
            ], 422);
        }

        $imageData = $request->input('image_data');
        if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,/', $imageData, $matches)) {
            return response()->json(['error' => 'Invalid image data.'], 422);
        }

        $rawData = base64_decode(substr($imageData, strpos($imageData, ',') + 1));
        $imageHash = md5($rawData);

        // Upload Cloudinary
        try {
            $tmpPath = tempnam(sys_get_temp_dir(), 'meme_');
            file_put_contents($tmpPath, $rawData);

            $result = $this->cloudinary()->uploadApi()->upload($tmpPath, [
                'folder' => 'memes',
                'resource_type' => 'image',
            ]);

            @unlink($tmpPath);

            $imageUrl = $result['secure_url'];
            $publicId = $result['public_id'];

        } catch (\Exception $e) {
            return response()->json(['error' => 'Image upload failed.'], 500);
        }

        Meme::create([
            'name' => $request->input('name'),
            'image_url' => $imageUrl,
            'public_id' => $publicId,
            'top_text' => $topText,
            'bottom_text' => $bottomText,
            'tags' => $request->input('tags'),
            'session_id' => $sessionId,
        ]);

        $newCount = $count + 1;

        return response()->json([
            'message' => 'Meme saved successfully!',
            'meme_url' => $imageUrl,
            'used' => $newCount,
            'remaining' => self::SESSION_LIMIT - $newCount,
        ], 201);
    }

    // DELETE /api/deleteMeme
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

        $meme->delete();

        return response()->json(['message' => 'Meme deleted successfully.']);
    }
}