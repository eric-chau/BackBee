<?php

/*
 * Copyright (c) 2011-2013 Lp digital system
 *
 * This file is part of BackBuilder5.
 *
 * BackBuilder5 is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * BackBuilder5 is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with BackBuilder5. If not, see <http://www.gnu.org/licenses/>.
 */

namespace BackBuilder\Command;

use BackBuilder\Console\ACommand;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Doctrine\ORM\Tools\SchemaTool;

/**
 * Clears cache
 *
 * @category    BackBuilder
 * @package     BackBuilder\Command
 * @copyright   Lp digital system
 * @author      k.golovin
 */
class BundleUpdateAllCommand extends ACommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('bundle:update_all')
            ->addOption('force', null, InputOption::VALUE_NONE, 'The update SQL will be executed against the DB')
            ->setDescription('Updates a bundle')
            ->setHelp(<<<EOF
The <info>%command.name%</info> updates all bundles: 

   <info>php bundle:update_all </info>
EOF
            )
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $force = $input->getOption('force');
        
        $bbapp = $this->getContainer()->get('bbapp');

        foreach($bbapp->getBundles() as $bundle) {
            $output->writeln('Updating bundle: ' . $bundle->getId() . '');

            $sqls = $bundle->getUpdateQueries($bundle->getBundleEntityManager());

            if($force) {
                $output->writeln('<info>Running update</info>');

                $bundle->update();
            } 

            $output->writeln('<info>SQL executed: </info>' . PHP_EOL . implode(";" . PHP_EOL, $sqls) . '');

        }
    }
    
}