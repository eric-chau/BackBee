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

namespace BackBee\Rest\Listener;

use BackBee\DependencyInjection\ContainerInterface;
use BackBee\Rest\Annotation\AnnotationHandlerInterface;

use Doctrine\Common\Annotations\AnnotationReader;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;

/**
 * @author Eric Chau <eric.chau@lp-digital.fr>
 */
class RestListener
{
    const ANNOTATION_HANDLER_TAG = 'rest.annotation_handler';

    private $annoReader;
    private $supportedPaths;
    private $annotationHandlers;

    /**
     * Creates an instance of RestListener and inject every service tagged with self::ANNOTATION_HANDLER_TAG.
     *
     * @param ContainerInterface $container
     * @param AnnotationReader   $annoReader
     * @param array              $supportedPaths
     */
    public function __construct(ContainerInterface $container, AnnotationReader $annoReader, array $supportedPaths = [])
    {
        $this->annoReader = $annoReader;
        $this->supportedPaths = $supportedPaths;

        $this->annotationHandlers = [];
        foreach ($container->findTaggedServiceIds(self::ANNOTATION_HANDLER_TAG) as $serviceId => $data) {
            $this->addAnnotationHandler($container->get($serviceId));
        }
    }

    /**
     * Registers provided handler as annotation handler.
     *
     * @param AnnotationHandlerInterface $handler The handler to add
     */
    public function addAnnotationHandler(AnnotationHandlerInterface $handler)
    {
        $this->annotationHandlers[] = $handler;

        return $this;
    }

    /**
     * Called on `kernel.controller` event.
     *
     * @param  FilterControllerEvent $event
     */
    public function onKernelController(FilterControllerEvent $event)
    {
        if (!$this->isSupportedRequest($request = $event->getRequest())) {
            return;
        }

        list($controller, $action) = $event->getController();
        $annotations = $this->annoReader->getMethodAnnotations(new \ReflectionMethod($controller, $action));
        foreach ($annotations as $annotation) {
            foreach ($this->annotationHandlers as $handler) {
                if ($handler->supports($annotation)) {
                    $handler->handle($annotation);
                }
            }
        }

        $event->stopPropagation();
    }

    /**
     * Returns true if provided request uri is supported, else false.
     *
     * @param  Request $request The request to test
     * @return boolean
     */
    private function isSupportedRequest(Request $request)
    {
        foreach ($this->supportedPaths as $basePath) {
            if (0 === strpos($request->getPathInfo(), $basePath)) {
                return true;
            }
        }

        return false;
    }
}
