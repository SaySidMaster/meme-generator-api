<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Meme;
use Illuminate\Http\Request;
use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;

class MemeController extends Controller
{
    private const SESSION_LIMIT = 5;
    private const SESSION_KEY = 'memes_created';
    private const SESSION_HASHES = 'memes_hashes';

    //Configure Cloudinary
    private function cloudinary(): Cloudinary
    {
        $config = new Configuration();
        $config->cloud->cloudName = env('CLOUDINARY_CLOUD_NAME');
        $config->cloud->apiKey = env('CLOUDINARY_API_KEY');
        $config->cloud->apiSecret = env('CLOUDINARY_API_SECRET');
        $config->url->secure = true;

        return new Cloudinary($config);
    }

    // Synchonisation du compteur de la session avec la base de données
    public function sessionInfo()
    {
        $sessionId = session()->getId();

        // Recalcule depuis la base pour eviter les incohérences avec la suppression de memes
        $count = Meme::where('session_id', $sessionId)->count();

        session([self::SESSION_KEY => $count]);

        return response()->json([
            'session_id' => $sessionId,
            'used' => $count,
            'limit' => self::SESSION_LIMIT,
            'remaining' => max(0, self::SESSION_LIMIT - $count),
        ]);
    }

    // Retourne la liste des memes, avec pagination et recherche
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

    // Génération d'un meme (upload + enregistrement)
    public function store(Request $request)
    {
        // Vérification de la limite de génération
        $sessionId = session()->getId();
        $count = Meme::where('session_id', $sessionId)->count();
        session([self::SESSION_KEY => $count]);

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

        // Vérification du format de l'image (data URI)
        $imageData = $request->input('image_data');
        if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,/', $imageData, $matches)) {
            return response()->json(['error' => 'Invalid image data.'], 422);
        }

        $rawData = base64_decode(substr($imageData, strpos($imageData, ',') + 1));

        // S'assurer de ne pas avoir de doublons dans la session (même image)
        // Utile pour éviter les enregistrements multiples en cas de rafraîchissement ou de double clic
        $imageHash = md5($rawData);
        $hashes = session(self::SESSION_HASHES, []);

        if (in_array($imageHash, $hashes)) {
            return response()->json([
                'error' => 'This meme has already been saved in this session.',
            ], 409);
        }

        // Upload vers Cloudinary
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
            return response()->json([
                'error' => 'Image upload failed ',// . $e->getMessage(),
            ], 500);
        }

        // Enregistrement en base de données
        Meme::create([
            'name' => $request->input('name'),
            'image_url' => $imageUrl,
            'public_id' => $publicId,
            'top_text' => $topText,
            'bottom_text' => $bottomText,
            'tags' => $request->input('tags'),
            'session_id' => $sessionId,
        ]);

        // Mise à jour session (compteur + hash de l'image)
        $hashes[] = $imageHash;
        $newCount = $count + 1;
        session([
            self::SESSION_KEY => $newCount,
            self::SESSION_HASHES => $hashes,
        ]);

        return response()->json([
            'message' => 'Meme saved successfully!',
            'meme_url' => $imageUrl,
            'used' => $newCount,
            'remaining' => self::SESSION_LIMIT - $newCount,
        ], 201);
    }

    // Suppression d'un meme
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