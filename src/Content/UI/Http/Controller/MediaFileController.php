<?php

declare(strict_types=1);

namespace App\Content\UI\Http\Controller;

use App\Content\Application\Contract\MediaFileLookupInterface;
use App\Content\Application\Media\DeliverableMediaFile;
use App\Content\Application\Media\MediaPublicUrlResolver;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Delivers media files of the CMS and the blog (ADR 039). Only files of existing public media items are sent, so no
 * path from the request reaches the filesystem. The routes are stateless: no session, no consent cookie, no usage
 * tracking.
 *
 * `/uploads/media/<filename>` keeps old URLs working until v0.4: a moved file is redirected to its new URL, a file
 * not yet moved is sent from the old upload directory.
 */
final readonly class MediaFileController
{
    private const int IMMUTABLE_MAX_AGE = 31_536_000;
    private const int LEGACY_MAX_AGE = 3_600;

    public function __construct(
        private MediaFileLookupInterface $lookup,
    ) {
    }

    #[Route(
        '/media/{year}/{month}/{name}',
        name: 'app_media_file',
        requirements: ['year' => '\d{4}', 'month' => '\d{2}', 'name' => '[0-9a-f-]{36}\.[a-z0-9]{1,8}'],
        methods: ['GET', 'HEAD'],
        stateless: true,
    )]
    public function file(Request $request, string $year, string $month, string $name): BinaryFileResponse
    {
        $storageKey = MediaPublicUrlResolver::storageKeyFromPath($year.'/'.$month.'/'.$name);
        $file = null !== $storageKey ? $this->lookup->findStoredFile($storageKey) : null;
        if (!$file instanceof DeliverableMediaFile) {
            throw new NotFoundHttpException();
        }

        return $this->send($request, $file);
    }

    #[Route(
        '/uploads/media/{filename}',
        name: 'app_media_legacy_file',
        requirements: ['filename' => '[^/]+'],
        methods: ['GET', 'HEAD'],
        stateless: true,
    )]
    public function legacyFile(Request $request, string $filename): Response
    {
        $movedUrl = $this->lookup->findMovedUrl($filename);
        if (null !== $movedUrl) {
            $response = new RedirectResponse($movedUrl, Response::HTTP_MOVED_PERMANENTLY);
            $response->setPublic();
            $response->setMaxAge(self::LEGACY_MAX_AGE);

            return $response;
        }

        $file = $this->lookup->findLegacyFile($filename);
        if (!$file instanceof DeliverableMediaFile) {
            throw new NotFoundHttpException();
        }

        return $this->send($request, $file);
    }

    private function send(Request $request, DeliverableMediaFile $file): BinaryFileResponse
    {
        $response = new BinaryFileResponse($file->absolutePath, Response::HTTP_OK, ['Content-Type' => $file->mimeType], true, null, true, true);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $file->downloadName,
            $this->asciiName($file->downloadName),
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setMaxAge($file->immutable ? self::IMMUTABLE_MAX_AGE : self::LEGACY_MAX_AGE);
        if ($file->immutable) {
            $response->setImmutable();
        }

        $response->isNotModified($request);

        return $response;
    }

    private function asciiName(string $name): string
    {
        $extension = pathinfo($name, \PATHINFO_EXTENSION);
        $base = new AsciiSlugger()->slug(pathinfo($name, \PATHINFO_FILENAME))->toString();
        $base = '' !== $base ? $base : 'file';

        return '' !== $extension ? $base.'.'.strtolower($extension) : $base;
    }
}
