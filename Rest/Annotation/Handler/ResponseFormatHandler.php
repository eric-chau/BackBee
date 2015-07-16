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

namespace BackBee\Rest\Annotation\Handler;

use BackBee\Rest\Annotation\ResponseFormat;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @author Eric Chau <eric.chau@lp-digital.fr>
 */
class ResponseFormatHandler extends AbstractHandler
{
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * {@inheritdoc}
     */
    public function handle($annotation)
    {
        parent::handle($annotation);

        $requestedFormat = null;
        foreach (array_map([$this->request, 'getFormat'], $this->request->getAcceptableContentTypes()) as $format) {
            if (in_array($format, $annotation->acceptedFormats)) {
                $requestedFormat = $format;
                break;
            }
        }

        if (null === $requestedFormat) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot find valid format in request headers. Valid formats for this service: "%s".',
                implode('", "', $annotation->acceptedFormats)
            ));
        }

        $this->request->attributes->set('responseFormat', $requestedFormat);
    }

    /**
     * {@inheritdoc}
     */
    public function supports($annotation)
    {
        return $annotation instanceof ResponseFormat;
    }
}
