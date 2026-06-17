<?php

declare(strict_types=1);

namespace App\Engagement\UI\Console\Command;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Engagement\Application\MonthlyReminderContentBuilder;
use App\Engagement\Application\MonthlyReminderSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[AsCommand(
    name: 'app:reminder:preview',
    description: 'Preview the monthly submission reminder email for a hospital.',
)]
final class MonthlyReminderPreviewCommand extends Command
{
    public function __construct(
        private readonly HospitalRepository $hospitalRepository,
        private readonly MonthlyReminderContentBuilder $contentBuilder,
        private readonly Environment $twig,
        private readonly MonthlyReminderSender $reminderSender,
        private readonly TranslatorInterface $translator,
        #[Autowire('%env(MAILER_DSN)%')] private readonly string $mailerDsn,
        #[Autowire('%kernel.environment%')] private readonly string $appEnvironment,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('hospital', null, InputOption::VALUE_REQUIRED, 'Hospital ID')
            ->addOption('send', null, InputOption::VALUE_NONE, 'Send the email to the hospital owner')
            ->addOption('ignore-opt-out', null, InputOption::VALUE_NONE, 'Send even if the owner opted out (like admin action)')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Write HTML to file path')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Reference date (Y-m-d) for period calculation');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hospitalId = (int) $input->getOption('hospital');
        if ($hospitalId <= 0) {
            $io->error('Option --hospital is required.');

            return Command::INVALID;
        }

        $hospital = $this->hospitalRepository->findById($hospitalId);
        if (!$hospital instanceof Hospital) {
            $io->error(sprintf('Hospital %d not found.', $hospitalId));

            return Command::FAILURE;
        }

        $referenceDate = null;
        $dateRaw = $input->getOption('date');
        if (\is_string($dateRaw) && '' !== $dateRaw) {
            $referenceDate = new \DateTimeImmutable($dateRaw, new \DateTimeZone('Europe/Berlin'));
        }

        $content = $this->contentBuilder->build($hospital, $referenceDate);
        $html = $this->twig->render('@Engagement/email/monthly_submission_reminder.html.twig', [
            'content' => $content,
            'app_title' => 'Preview',
        ]);

        $outputPath = $input->getOption('output');
        $shouldSend = true === $input->getOption('send');
        if (\is_string($outputPath) && '' !== $outputPath) {
            file_put_contents($outputPath, $html);
            $io->success(sprintf('Wrote preview to %s', $outputPath));
        } elseif (!$shouldSend) {
            $io->writeln($html);
        }

        if ($shouldSend) {
            if ('dev' !== $this->appEnvironment && str_starts_with($this->mailerDsn, 'null://')) {
                $io->warning('MAILER_DSN is set to a null transport — no mail will be delivered. Set MAILER_DSN=smtp://127.0.0.1:1025 for Mailpit.');
            } elseif ('dev' === $this->appEnvironment && !$this->isSmtpReachable('127.0.0.1', 1025)) {
                $io->error('Mailpit is not reachable on 127.0.0.1:1025. Start it with: docker compose up -d mailer');

                return Command::FAILURE;
            }

            $trigger = $input->getOption('ignore-opt-out')
                ? MonthlyReminderTrigger::Admin
                : MonthlyReminderTrigger::Cli;

            $errors = $this->reminderSender->sendForHospital($hospital, $trigger, $referenceDate);
            if ([] !== $errors) {
                foreach ($errors as $errorKey) {
                    $io->error($this->translator->trans($errorKey));
                }
                if ('monthly_reminder.error.opted_out' === ($errors[0] ?? null)) {
                    $io->note('Use --ignore-opt-out to send anyway for manual testing.');
                }

                return Command::FAILURE;
            }
            $owner = $hospital->getOwner();
            $email = $owner?->getEmail();
            $io->success(sprintf('Sent reminder to %s (check Mailpit at http://127.0.0.1:8025)', $email ?? 'unknown'));
        }

        return Command::SUCCESS;
    }

    private function isSmtpReachable(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 1.0);

        if (false === $socket) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
