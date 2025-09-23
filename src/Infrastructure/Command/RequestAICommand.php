<?php

declare(strict_types=1);

namespace App\Infrastructure\Command;

use App\Application\Handler\GetRequestAIHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'artifihair:request-ai', description: 'Demande ce que tu veux sur les salons de coiffures ayant un nom rigolo')]
class RequestAICommand extends Command
{
    public function __construct(private readonly GetRequestAIHandler $getRequestAIHandler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('request', InputArgument::REQUIRED, 'Votre demande');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $request = (string) $input->getArgument('request');

        $response = $this->getRequestAIHandler->handle($request);

        $output->writeln($response);

        return Command::SUCCESS;
    }
}
