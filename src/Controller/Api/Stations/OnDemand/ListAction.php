<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\OnDemand;

use App\Doctrine\Paginator\HydratingAdapter;
use App\Entity;
use App\Entity\StationMedia;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Paginator;
use App\Service\Meilisearch;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface;

final class ListAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Entity\ApiGenerator\SongApiGenerator $songApiGenerator,
        private readonly Meilisearch $meilisearch
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        string $station_id
    ): ResponseInterface {
        $station = $request->getStation();

        // Verify that the station supports on-demand streaming.
        if (!$station->getEnableOnDemand()) {
            return $response->withStatus(403)
                ->withJson(new Entity\Api\Error(403, __('This station does not support on-demand streaming.')));
        }

        $queryParams = $request->getQueryParams();
        $searchPhrase = trim($queryParams['searchPhrase'] ?? '');

        $sortField = (string)($queryParams['sort'] ?? '');
        $sortDirection = strtolower($queryParams['sortOrder'] ?? 'asc');

        if ($this->meilisearch->isSupported()) {
            $index = $this->meilisearch->getIndex($station->getMediaStorageLocation());

            $searchParams = [];
            if (!empty($sortField)) {
                $searchParams['sort'] = [$sortField . ':' . $sortDirection];
            }

            $paginatorAdapter = $index->getOnDemandSearchPaginator(
                $station,
                $searchPhrase,
                $searchParams,
            );

            $hydrateCallback = function (iterable $results) {
                $ids = array_column([...$results], 'id');

                return $this->em->createQuery(
                    <<<'DQL'
                    SELECT sm
                    FROM App\Entity\StationMedia sm
                    WHERE sm.id IN (:ids)
                    ORDER BY FIELD(sm.id, :ids)
                DQL
                )->setParameter('ids', $ids)
                    ->toIterable();
            };

            $hydrateAdapter = new HydratingAdapter(
                $paginatorAdapter,
                $hydrateCallback(...)
            );

            $paginator = Paginator::fromAdapter($hydrateAdapter, $request);
        } else {
            $playlistsRaw = $this->em->createQuery(
                <<<'DQL'
                SELECT sp.id FROM App\Entity\StationPlaylist sp
                WHERE sp.station = :station
                AND sp.is_enabled = 1 AND sp.include_in_on_demand = 1
                DQL
            )->setParameter('station', $station)
                ->getArrayResult();

            $playlistIds = array_column($playlistsRaw, 'id');

            $qb = $this->em->createQueryBuilder();
            $qb->select('sm, spm, sp')
                ->from(StationMedia::class, 'sm')
                ->leftJoin('sm.playlists', 'spm')
                ->leftJoin('spm.playlist', 'sp')
                ->where('sm.storage_location = :storageLocation')
                ->andWhere('sp.id IN (:playlistIds)')
                ->setParameter('storageLocation', $station->getMediaStorageLocation())
                ->setParameter('playlistIds', $playlistIds);

            if (!empty($sortField)) {
                match ($sortField) {
                    'name', 'title' => $qb->addOrderBy('sm.title', $sortDirection),
                    'artist' => $qb->addOrderBy('sm.artist', $sortDirection),
                    'album' => $qb->addOrderBy('sm.album', $sortDirection),
                    'genre' => $qb->addOrderBy('sm.genre', $sortDirection),
                    default => null,
                };
            } else {
                $qb->orderBy('sm.artist', 'ASC')
                    ->addOrderBy('sm.title', 'ASC');
            }

            if (!empty($searchPhrase)) {
                $qb->andWhere('(sm.title LIKE :query OR sm.artist LIKE :query OR sm.album LIKE :query)')
                    ->setParameter('query', '%' . $searchPhrase . '%');
            }

            $paginator = Paginator::fromQueryBuilder($qb, $request);
        }

        $router = $request->getRouter();

        $paginator->setPostprocessor(
            function (Entity\StationMedia $media) use ($station, $router) {
                $row = new Entity\Api\StationOnDemand();

                $row->track_id = $media->getUniqueId();
                $row->media = ($this->songApiGenerator)(
                    song: $media,
                    station: $station
                );

                $row->download_url = $router->named(
                    'api:stations:ondemand:download',
                    [
                        'station_id' => $station->getId(),
                        'media_id' => $media->getUniqueId(),
                    ]
                );

                $row->resolveUrls($router->getBaseUrl());

                return $row;
            }
        );

        return $paginator->write($response);
    }
}
