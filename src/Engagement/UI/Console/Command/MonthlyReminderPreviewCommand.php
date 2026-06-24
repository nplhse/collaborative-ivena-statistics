<?php

declare(strict_types=1);

namespace App\Engagement\UI\Console\Command;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Engagement\Application\MonthlyReminderContentBuilder;
use App\Engagement\Application\MonthlyReminderSender;
use App\Engagement\UI\Console\Input\MonthlyReminderPreviewInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[AsCommand(
    name: 'app:reminder:preview',
    description: 'Preview the monthly submission reminder email for a hospital.',
)]
final readonly class MonthlyReminderPreviewCommand
{
    public function __construct(
        private HospitalRepository $hospitalRepository,
        private MonthlyReminderContentBuilder $contentBuilder,
        private Environment $twig,
        private MonthlyReminderSender $reminderSender,
        private TranslatorInterface $translator,
        #[Autowire('%env(MAILER_DSN)%')] private string $mailerDsn,
        #[Autowire('%kernel.environment%')] private string $appEnvironment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] MonthlyReminderPreviewInput $input,
    ): int {
        if (null === $input->hospital || $input->hospital <= 0) {
            $io->error('Option --hospital is required.');

            return Command::INVALID;
        }

        $hospital = $this->hospitalRepository->findById($input->hospital);
        if (!$hospital instanceof Hospital) {
            $io->error(sprintf('Hospital %d not found.', $input->hospital));

            return Command::FAILURE;
        }

        $referenceDate = $input->date;

        $content = $this->contentBuilder->build($hospital, $referenceDate);
        $html = $this->twig->render('@Engagement/email/monthly_submission_reminder.html.twig', [
            'content' => $content,
            'app_title' => 'Preview',
        ]);

        $outputPath = $input->output;
        $shouldSend = $input->send;
        if (null !== $outputPath && '' !== $outputPath) {
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

            $trigger = $input->ignoreOptOut
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
