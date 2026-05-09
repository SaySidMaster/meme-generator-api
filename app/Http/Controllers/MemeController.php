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

    private function cloudinary(): Cloudinary
    {
        $config = new Configuration();
        $config->cloud->cloudName = env('CLOUDINARY_CLOUD_NAME');
        $config->cloud->apiKey = env('CLOUDINARY_API_KEY');
        $config->cloud->apiSecret = env('CLOUDINARY_API_SECRET');
        $config->url->secure = true;

        return new Cloudinary($config);
    }
    private function resolveSessionId(Request $request): string
    {
        $sessionId = $request->header('X-Session-Id');
        
       if (!$sessionId) {
            $sessionId = Str::uuid()->toString();
        }
        
        return $sessionId;
    }

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

    public function index(Request $request)
    {
        $query = Meme::query()->latest();

        // Recherche par nom ou tags
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

    public function store(Request $request)
    {
        $sessionId = $this->resolveSessionId($request);
        $count = Meme::where('session_id', $sessionId)->count();

        // Limite de 5 memes par session
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

        // Au moins un texte requis
        if ($topText === '' && $bottomText === '') {
            return response()->json([
                'error' => 'At least one of Top Text or Bottom Text must be filled in.',
            ], 422);
        }

        $imageData = $request->input('image_data');
        // Validation du format image base64
        if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,/', $imageData, $matches)) {
            return response()->json(['error' => 'Invalid image data.'], 422);
        }

        $rawData = base64_decode(substr($imageData, strpos($imageData, ',') + 1));

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