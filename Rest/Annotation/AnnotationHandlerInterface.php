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

namespace BackBee\Rest\Annotation;

/**
 * @author Eric Chau <eric.chau@lp-digital.fr>
 */
interface AnnotationHandlerInterface
{
    /**
     * Handle the provided annotation and to specific stuff.
     *
     * @param  object $annotation
     * @throws \InvalidArgumentException if provided annotation is not supported
     */
    public function handle($annotation);

    /**
     * Returns true if provided annotation is supported by current handler, else false.
     *
     * @param  object $annotation
     * @return boolean
     */
    public function supports($annotation);
}
