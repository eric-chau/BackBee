<?php

/*
 * Copyright (c) 2011-2015 Lp digital system
 *
 * This file is part of BackBee.
 *
 * BackBee is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * BackBee is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with BackBee. If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Charles Rouillon <charles.rouillon@lp-digital.fr>
 */

namespace BackBee\Event\Listener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Util\ClassUtils;
use BackBee\ApplicationInterface;
use BackBee\Cache\AbstractExtendedCache;
use BackBee\Cache\CacheIdentifierGenerator;
use BackBee\Cache\CacheValidator;
use BackBee\ClassContent\AbstractClassContent;
use BackBee\ClassContent\ContentSet;
use BackBee\Event\Event;
use BackBee\NestedNode\Page;
use BackBee\Renderer\AbstractRenderer;
use BackBee\Renderer\Event\RendererEvent;
use BackBee\Util\Doctrine\ScheduledEntities;

/**
 * Listener to Cache events.
 *
 * @category    BackBee
 *
 * @copyright   Lp digital system
 * @author      c.rouillon <charles.rouillon@lp-digital.fr>, e.chau <eric.chau@lp-digital.fr>
 */
class CacheListener implements EventSubscriberInterface
{
    /**
     * The current application instance.
     *
     * @var \BackBee\BBApplication
     */
    private $application;

    /**
     * cache validator.
     *
     * @var BackBee\Cache\CacheValidator
     */
    private $validator;

    /**
     * cache identifier generator.
     *
     * @var BackBee\Cache\CacheIdentifierGenerator
     */
    private $identifierGenerator;

    /**
     * The page cache system.
     *
     * @var \BackBee\Cache\AbstractExtendedCache
     */
    private $cachePage;

    /**
     * The content cache system.
     *
     * @var \BackBee\Cache\AbstractExtendedCache
     */
    private $cacheContent;

    /**
     * The object to be rendered.
     *
     * @var \BackBee\Renderer\RenderableInterface
     */
    private $object;

    /**
     * Is the deletion of cached page is done.
     *
     * @var boolean
     */
    private $pageCacheDeletionDone = false;

    /**
     * Cached contents already deleted.
     *
     * @var boolean
     */
    private $contentCacheDeletionDone = [];

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * @return array The event names to listen to
     */
    public static function getSubscribedEvents()
    {
        return [
            'classcontent.prerender'     => 'onPreRenderContent',
            'classcontent.postrender'    => 'onPostRenderContent',
            'classcontent.onflush'       => 'onFlushContent',
            'nestednode.page.prerender'  => 'onPreRenderPage',
            'nestednode.page.postrender' => 'onPostRenderPage',
            'nestednode.page.onflush'    => 'onFlushPage',
        ];
    }

    /**
     * constructor.
     *
     * @param ApplicationInterface     $application
     * @param CacheValidator           $validator
     * @param CacheIdentifierGenerator $generator
     */
    public function __construct(ApplicationInterface $application, CacheValidator $validator, CacheIdentifierGenerator $generator)
    {
        $this->application = $application;
        $this->validator = $validator;
        $this->identifierGenerator = $generator;

        if ($this->application->getContainer()->has('cache.content')) {
            $cacheContent = $this->application->getContainer()->get('cache.content');
            if ($cacheContent instanceof AbstractExtendedCache) {
                $this->cacheContent = $cacheContent;
            }
        }

        if ($this->application->getContainer()->has('cache.page')) {
            $cachePage = $this->application->getContainer()->get('cache.page');
            if ($cachePage instanceof AbstractExtendedCache) {
                $this->cachePage = $cachePage;
            }
        }
    }

    /**
     * Looks for available cached data before rendering a content.
     *
     * @param \BackBee\Event\Event $event
     */
    public function onPreRenderContent(RendererEvent $event)
    {
        // Checks if content caching is available
        $this->object = $event->getTarget();

        if (!($this->object instanceof AbstractClassContent) || !$this->checkCacheContentEvent()) {
            return;
        }

        $renderer = $event->getRenderer();
        // Checks if cache data is available
        $cacheId = $this->getContentCacheId($renderer);
        if (false === $data = $this->cacheContent->load($cacheId)) {
            return;
        }

        $renderer->setRender($data);
        $event->getDispatcher()->dispatch('cache.postrender', new Event($this->object, array($renderer, $data)));
        $this->application->debug(sprintf(
            'Found cache (id: %s) for rendering `%s(%s)` with mode `%s`.',
            $cacheId,
            get_class($this->object),
            $this->object->getUid(),
            $renderer->getMode()
        ));
    }

    /**
     * Saves in cache the rendered cache data.
     *
     * @param \BackBee\Event\Event $event
     */
    public function onPostRenderContent(RendererEvent $event)
    {
        // Checks if content caching is available
        $this->object = $event->getTarget();
        if (!($this->object instanceof AbstractClassContent) || !$this->checkCacheContentEvent()) {
            return;
        }

        $renderer = $event->getRenderer();
        // Checks if cacheId is available
        if (false === $cacheId = $this->getContentCacheId($renderer)) {
            return;
        }

        // Gets the lifetime to set
        if (null === $lifetime = $this->object->getProperty('cache-lifetime')) {
            $lifetime = 0;
        }

        // Computes $lifetime according param and children
        $uids = $this->application->getEntityManager()->getRepository(ClassUtils::getRealClass($this->object))
            ->getUnorderedChildrenUids($this->object)
        ;

        $lifetime = $this->cacheContent->getMinExpireByTag($uids, $lifetime);

        $render = $event->getRender();
        $this->cacheContent->save($cacheId, $render, $lifetime, $this->object->getUid());
        $this->application->debug(sprintf(
            'Save cache (id: %s, lifetime: %d) for rendering `%s(%s)` with mode `%s`.',
            $cacheId,
            $lifetime,
            get_class($this->object),
            $this->object->getUid(),
            $renderer->getMode()
        ));
    }

    /**
     * Clears cached data associated to the content to be flushed.
     *
     * @param \BackBee\Event\Event $event
     */
    public function onFlushContent(Event $event)
    {
        // Checks if page caching is available
        $this->object = $event->getTarget();
        if (!($this->object instanceof AbstractClassContent) || !$this->checkCacheContentEvent(false)) {
            return;
        }

        $parentUids = $this->application->getEntityManager()
            ->getRepository('BackBee\ClassContent\Indexes\IdxContentContent')
            ->getParentContentUids([$this->object])
        ;

        $contentUids = array_diff($parentUids, $this->contentCacheDeletionDone);
        if (0 === count($contentUids)) {
            return;
        }

        $this->cacheContent->removeByTag($contentUids);
        $this->contentCacheDeletionDone = array_merge($this->contentCacheDeletionDone, $contentUids);
        $this->application->debug(sprintf(
            'Remove cache for `%s(%s)`.',
            get_class($this->object),
            implode(', ', $contentUids)
        ));

        if (false === $this->application->getContainer()->has('cache.page')) {
            return;
        }

        $cachePage = $this->application->getContainer()->get('cache.page');
        if (0 < count($contentUids) && $cachePage instanceof AbstractExtendedCache) {
            $pageUids = $this->getContentsPageUids($contentUids);
            $cachePage->removeByTag($pageUids);
            $this->application->debug(sprintf('Remove cache for page %s.', implode(', ', $pageUids)));
        }
    }

    /**
     * Looks for available cached data before rendering a page.
     *
     * @param \BackBee\Event\Event $event
     */
    public function onPreRenderPage(RendererEvent $event)
    {
        // Checks if page caching is available
        $this->object = $event->getTarget();

        if (!($this->object instanceof Page) || false === $this->checkCachePageEvent()) {
            return;
        }

        // Checks if cache data is available
        $cacheId = $this->getPageCacheId();
        if (false === $data = $this->cachePage->load($cacheId)) {
            return;
        }

        $renderer = $event->getRenderer();
        $renderer->setRender($data);
        $event->getDispatcher()->dispatch('cache.postrender', new Event($this->object, [$renderer, $data]));
        $this->application->debug(sprintf(
            'Found cache (id: %s) for rendering `%s(%s)` with mode `%s`.',
            $cacheId,
            get_class($this->object),
            $this->object->getUid(),
            $renderer->getMode()
        ));
    }

    /**
     * Saves in cache the rendered page data.
     *
     * @param \BackBee\Event\Event $event
     */
    public function onPostRenderPage(RendererEvent $event)
    {
        // Checks if page caching is available
        $this->object = $event->getTarget();
        if (!($this->object instanceof Page) || !$this->checkCachePageEvent()) {
            return;
        }

        // Checks if cacheId is available
        if (false === $cacheId = $this->getPageCacheId()) {
            return;
        }

        $columnUids = [];
        foreach ($this->object->getContentSet() as $column) {
            if ($column instanceof AbstractClassContent) {
                $columnUids[] = $column->getUid();
            }
        }

        $lifetime = $this->cachePage->getMinExpireByTag($columnUids);
        $render = $event->getRender();
        $this->cachePage->save($cacheId, $render, $lifetime, $this->object->getUid());
        $this->application->debug(sprintf(
            'Save cache (id: %s, lifetime: %d) for rendering `%s(%s)` with mode `%s`.',
            $cacheId,
            $lifetime,
            get_class($this->object),
            $this->object->getUid(),
            $event->getRenderer()->getMode()
        ));
    }

    /**
     * Clears cached data associated to the page to be flushed.
     *
     * @param \BackBee\Event\Event $event
     */
    public function onFlushPage(Event $event)
    {
        // Checks if page caching is available
        $this->object = $event->getTarget();
        if (!($this->object instanceof Page) || !$this->checkCachePageEvent(false)) {
            return;
        }

        if ($this->pageCacheDeletionDone) {
            return;
        }

        $pages = ScheduledEntities::getScheduledEntityUpdatesByClassname(
            $this->application->getEntityManager(), 'BackBee\NestedNode\Page'
        );

        if (0 === count($pages)) {
            return;
        }

        $pageUids = [];
        foreach ($pages as $page) {
            $pageUids[] = $page->getUid();
        }

        $this->cachePage->removeByTag($pageUids);
        $this->pageCacheDeletionDone = true;
        $this->application->debug(sprintf(
            'Remove cache for `%s(%s)`.',
            get_class($this->object),
            implode(', ', $pageUids)
        ));
    }

    /**
     * Checks the event and system validity then returns the content target, FALSE otherwise.
     *
     * @param \BackBee\Event\Event $event
     * @param boolean              $checkStatus
     *
     * @return boolean
     */
    private function checkCacheContentEvent($checkStatus = true)
    {
        // Checks if a service cache-control exists
        if (null === $this->cacheContent) {
            return false;
        }

        // Checks if the target event is not a main contentset
        if (
            $this->object instanceof ContentSet
            && is_array($this->object->getPages())
            && 0 < $this->object->getPages()->count()
        ) {
            return false;
        }

        return true === $checkStatus ? $this->validator->isValid('cache_status', $this->object) : true;
    }

    /**
     * Checks the event and system validity then returns the page target, FALSE otherwise.
     *
     * @param \BackBee\Event\Event $event
     * @param boolean              $checkStatus
     *
     * @return boolean
     */
    private function checkCachePageEvent($checkStatus = true)
    {
        return null !== $this->cachePage
            && true === $this->validator->isValid('page', $this->application->getRequest()->getUri())
            && (
                true === $checkStatus
                    ? $this->validator->isValid('cache_status', $this->object)
                    : true
            )
        ;
    }

    /**
     * Return the cache id for the current rendered content.
     *
     * @return string|FALSE
     */
    private function getContentCacheId(AbstractRenderer $renderer)
    {
        $cacheId = $this->identifierGenerator->compute(
            'content', $this->object->getUid().'-'.$renderer->getMode(),
            $renderer
        );

        return md5('_content_'.$cacheId);
    }

    /**
     * Return the cache id for the current requested page.
     *
     * @return string|FALSE
     */
    private function getPageCacheId()
    {
        return $this->application->getRequest()->getUri();
    }

    private function getContentsPageUids(array $contentUids)
    {
        $contentUids = implode(', ', array_map(function ($uid) {
            return '"'.$uid.'"';
        }, $contentUids));

        $contentUids = $this->application->getEntityManager()->getConnection()->executeQuery(sprintf(
            'SELECT c.uid
             FROM content c
             WHERE c.uid IN (SELECT cs.parent_uid FROM content_has_subcontent cs WHERE cs.content_uid IN (%s))',
            $contentUids
        ))->fetchAll(\PDO::FETCH_COLUMN);

        if (0 === count($contentUids)) {
            return [];
        }

        $contentUids = implode(', ', array_map(function ($uid) {
            return '"'.$uid.'"';
        }, $contentUids));

        return $this->application->getEntityManager()->getConnection()->executeQuery(sprintf(
            'SELECT p.uid
             FROM content c, page p
             WHERE c.uid IN (SELECT cs.parent_uid FROM content_has_subcontent cs WHERE cs.content_uid IN (%s))
             AND c.uid = p.contentset',
            $contentUids
        ))->fetchAll(\PDO::FETCH_COLUMN);

        // return $this->application->getEntityManager()->getRepository('BackBee\NestedNode\Page')->findBy([
        //     '_uid' => array_merge(['ca821970fdba9c0a9064a01360620220'], $pageUids),
        // ]);
    }
}
