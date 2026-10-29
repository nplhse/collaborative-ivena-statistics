<?php

declare(strict_types=1);

namespace App\Engagement\UI\Console\Command;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Engagement\Application\Dto\MonthlyReminderTrigger;
use App\Engagement\Application\MonthlyReminderContentBuilder;
use App\Engagement\Application\MonthlyReminderSender;
use App\Engagement\UI\Console\Input\MonthlyReminderPreviewInput;
use App\Shared\Application\Locale\LocaleResolver;
use App\Shared\Infrastructure\Mail\LocalMailCatcher;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'app:reminder:preview',
    description: 'Preview the monthly submission reminder email for a hospital.',
)]
final readonly class MonthlyReminderPreviewCommand
{
    public function __construct(
        private HospitalRepository $hospitalRepository,
        private MonthlyReminderContentBuilder $contentBuilder,
        private BodyRendererInterface $bodyRenderer,
        private LocaleResolver $localeResolver,
        private MonthlyReminderSender $reminderSender,
        private TranslatorInterface $translator,
        private LocalMailCatcher $mailCatcher,
        #[Autowire(param: 'app.mailer_dsn')] private string $mailerDsn,
        #[Autowire(param: 'kernel.environment')] private string $appEnvironment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput] MonthlyReminderPreviewInput $input,
    ): int {
        if (null === $input->hospitalId || $input->hospitalId <= 0) {
            $io->error('Option --hospital-id is required.');

            return Command::INVALID;
        }

        $hospital = $this->hospitalRepository->findById($input->hospitalId);
        if (!$hospital instanceof Hospital) {
            $io->error(sprintf('Hospital %d not found.', $input->hospitalId));

            return Command::FAILURE;
        }

        // Delivers to the local mail catcher: always in dev, in the demo only with DEMO_MAIL_CATCHER=1.
        $catcherMode = LocalMailCatcher::receives($this->mailerDsn);
        if ($input->send) {
            if ('demo' === $this->appEnvironment && !$catcherMode) {
                $io->error('Mail is off in the demo environment; nothing was sent. Start the mail catcher (docker compose up -d mailer) and run the command with DEMO_MAIL_CATCHER=1, e.g. make demo-reminder HOSPITAL=<id>.');

                return Command::FAILURE;
            }
            if ($catcherMode && !$this->mailCatcher->isReachable()) {
                $io->error(sprintf('Mailpit is not reachable on %s:%d. Start it with: docker compose up -d mailer', LocalMailCatcher::HOST, LocalMailCatcher::SMTP_PORT));

                return Command::FAILURE;
            }
            if (!$catcherMode && str_starts_with($this->mailerDsn, 'null://')) {
                $io->warning('MAILER_DSN is set to a null transport — no mail will be delivered. Set MAILER_DSN=smtp://127.0.0.1:1025 for Mailpit.');
            }
        }

        $referenceDate = $input->date;
        $owner = $hospital->getOwner();
        $ownerLocale = $this->localeResolver->resolveForUser($owner);

        $content = $this->contentBuilder->build($hospital, $referenceDate, $ownerLocale);
        $previewEmail = new TemplatedEmail()
            ->locale($ownerLocale)
            ->htmlTemplate('@Engagement/email/monthly_submission_reminder.html.twig')
            ->context([
                'content' => $content,
                'app_title' => 'Preview',
            ]);
        $this->bodyRenderer->render($previewEmail);
        $html = (string) $previewEmail->getHtmlBody();

        $outputPath = $input->output;
        $shouldSend = $input->send;
        if (null !== $outputPath && '' !== $outputPath) {
            file_put_contents($outputPath, $html);
            $io->success(sprintf('Wrote preview to %s', $outputPath));
        } elseif (!$shouldSend) {
            $io->writeln($html);
        }

        if ($shouldSend) {
            $errors = $this->reminderSender->sendForHospital($hospital, MonthlyReminderTrigger::Cli, $referenceDate);
            if ([] !== $errors) {
                foreach ($errors as $errorKey) {
                    $io->error($this->translator->trans($errorKey, [], 'engagement'));
                }
                if ('monthly_reminder.error.opted_out' === ($errors[0] ?? null)) {
                    $io->note('The owner switched the monthly reminder off in their notification settings.');
                }

                return Command::FAILURE;
            }
            $owner = $hospital->getOwner();
            $email = $owner?->getEmail();
            $io->success(sprintf('Sent reminder to %s', $email ?? 'unknown'));
            if ($catcherMode) {
                $io->writeln(sprintf('Open the mail catcher: %s', LocalMailCatcher::WEB_UI_URL));
            }
        }

        return Command::SUCCESS;
    }
}
