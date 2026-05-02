<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class FirebaseStorageService
{
    protected $storage;
    protected $bucketName;

    public function __construct()
    {
        try {
            $factory = (new Factory)->withServiceAccount(config('services.firebase.credentials'));
            $this->storage = $factory->createStorage();
            $this->bucketName = 'parentndtg.firebasestorage.app';
        } catch (\Exception $e) {
            Log::error('Firebase Storage Init Error: ' . $e->getMessage());
        }
    }

    /**
     * Upload a file to Firebase Storage and return its public URL
     *
     * @param UploadedFile $file The file to upload
     * @param string $folder The folder path in the bucket (e.g., 'eleves', 'professeurs')
     * @return string|null The public URL of the uploaded file, or null on failure
     */
    public function uploadFile(UploadedFile $file, string $folder = 'uploads'): ?string
    {
        if (!$this->storage) {
            return null;
        }

        try {
            $bucket = $this->storage->getBucket($this->bucketName);
            $extension = $file->getClientOriginalExtension();
            $filename = Str::uuid() . '_' . time() . '.' . $extension;
            $firebasePath = trim($folder, '/') . '/' . $filename;

            // Upload the file
            $bucket->upload(
                fopen($file->getPathname(), 'r'),
                [
                    'name' => $firebasePath,
                ]
            );

            // Generate public URL using Firebase Storage format:
            $encodedPath = urlencode($firebasePath);
            return "https://firebasestorage.googleapis.com/v0/b/{$this->bucketName}/o/{$encodedPath}?alt=media";

        } catch (\Exception $e) {
            Log::error('Firebase Storage Upload Error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Delete a file from Firebase Storage using its URL
     */
    public function deleteFile(string $url): bool
    {
        if (!$this->storage || empty($url)) {
            return false;
        }
        
        try {
            if (strpos($url, 'firebasestorage.googleapis.com') !== false) {
                if (preg_match('/\/o\/(.*?)(?:\?|$)/', $url, $matches)) {
                    $firebasePath = urldecode($matches[1]);
                    $bucket = $this->storage->getBucket($this->bucketName);
                    $object = $bucket->object($firebasePath);
                    if ($object->exists()) {
                        $object->delete();
                        return true;
                    }
                }
            }
            return false;
        } catch (\Exception $e) {
            Log::error('Firebase Storage Delete Error: ' . $e->getMessage());
            return false;
        }
    }
}
