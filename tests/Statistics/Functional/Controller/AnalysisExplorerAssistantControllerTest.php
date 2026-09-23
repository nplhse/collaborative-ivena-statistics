<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\Tests\Support\Statistics\RefreshesStatisticsFunctionalDataTrait;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AnalysisExplorerAssistantControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use RefreshesStatisticsFunctionalDataTrait;

    public function testFirstStepShowsFourGoalCards(): void
    {
        $client = $this->createClientAsParticipant();

        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/assistant?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-assistant-title"]',
            'Analysis assistant',
        );
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-goal-time_series"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-goal-distribution"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-goal-toplist"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-goal-matrix"]');
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-assistant-goal-time_series"]',
            'Development over time',
        );
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-goals"].row-cols-2');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-step-title"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-step-goal"][aria-current="step"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-assistant-jump-goal"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-step-context"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-step-source"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-step-questions"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-step-filters"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-step-summary"]');
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-assistant-step-lead"]',
            'Choose a question.',
        );
        $back = $client->getCrawler()->filter('[data-testid="stats-analysis-explorer-assistant-card"] .card-footer [data-testid="stats-analysis-explorer-assistant-back"]');
        self::assertSame('Cancel', trim($back->text()));
        $cancelUrl = (string) $back->attr('href');
        self::assertStringContainsString('/statistics/analysis/assistant/cancel', $cancelUrl);
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-assistant-scope"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-context-trigger"]');
        $this->assertSelectorNotExists('[data-testid="statistics-filter-gender"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-assistant-row"]');

        $client->request(Request::METHOD_GET, $cancelUrl);
        $this->assertResponseRedirects();
        self::assertStringContainsString(
            '/statistics/analysis/library',
            (string) $client->getResponse()->headers->get('Location'),
        );
    }

    public function testTimeSeriesStepAsksForGroupingAndSummarizesTheProposal(): void
    {
        $client = $this->createClientAsParticipant();

        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/assistant?scope=public&period=all',
        );
        $this->submitButton($client, 'Development over time');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-step-source"][aria-current="step"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-jump-goal"]');
        $this->submitButton($client, 'Allocations');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-step-context"][aria-current="step"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-scope"] option[value="public"][selected]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-period"] option[value="all"][selected]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-assistant-grain"]');

        $this->submitChoices($client, 'Next', [
            'explorer_assistant[context][scopePeriod][period]' => 'year',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-row"] option[value="time"][selected]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-grain"] option[value="year"][selected]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-structure-row"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-assistant-structure-column"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-structure-metric"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-assistant-column"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-metric"] option[value="allocation_count"][selected]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-assistant-summary"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-next"]:not([disabled])');

        $this->submitButton($client, 'Next');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-step-filters"][aria-current="step"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-filters"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-next"]:not([disabled])');

        $this->submitButton($client, 'Next');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-assistant-summary-title"]',
            'Allocations over time',
        );
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-assistant-summary-presentation"]',
            'line chart',
        );
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-assistant-summary-presentation"]',
            'The chart shows Allocations.',
        );

        $this->submitButton($client, 'Back');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-filters"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-assistant-summary"]');

        $this->submitButton($client, 'Next');
        $this->submitButton($client, 'Open in Analysis Explorer');

        $this->assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/statistics/analysis/explorer', $location);
        self::assertStringContainsString('guide=time_series', $location);
        self::assertStringContainsString('grain=year', $location);
        self::assertStringContainsString('metric=allocation_count', $location);
        self::assertStringNotContainsString('measure=', $location);
        self::assertStringContainsString('scope=public', $location);
        self::assertStringContainsString('period=year', $location);
        self::assertStringContainsString('year=', $location);
    }

    public function testDistributionStepAcceptsAManuallyChosenCharacteristic(): void
    {
        $client = $this->createClientAsParticipant();

        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/assistant?scope=public&period=all',
        );
        $this->submitButton($client, 'Distribution of a characteristic');
        $this->submitButton($client, 'Allocations');
        $this->submitButton($client, 'Next');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-row"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-assistant-column"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-metric"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-assistant-grain"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-assistant-summary"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-next"]:not([disabled])');

        $this->submitButton($client, 'Next');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-assistant-notice"]',
            'Choose the characteristics for this question first.',
        );
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-assistant-summary"]');

        $this->submitChoices($client, 'Next', [
            'explorer_assistant[questions][row]' => 'urgency',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-filters"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-assistant-filter-urgency"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-filter-gender"]');

        $this->submitChoices($client, 'Next', [
            'explorer_assistant[filters][filterGender]' => '2',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-assistant-summary-presentation"]',
            'bar chart',
        );
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-assistant-summary-presentation"]',
            'The chart shows Allocations, limited to Gender: Female.',
        );
        $this->assertSelectorTextNotContains(
            '[data-testid="stats-analysis-explorer-assistant-summary-presentation"]',
            'share of the total',
        );

        $this->submitButton($client, 'Open in Analysis Explorer');
        $this->assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('guide=distribution', $location);
        self::assertStringContainsString('row=urgency', $location);
        self::assertStringContainsString('metric=allocation_count', $location);
        self::assertStringContainsString('gender=2', $location);
        self::assertStringNotContainsString('measure=', $location);
    }

    public function testContextWithoutAPeriodStartsAtTheFullHistory(): void
    {
        $client = $this->createClientAsParticipant();

        $client->request(Request::METHOD_GET, '/statistics/analysis/assistant?scope=public');
        $this->submitButton($client, 'Development over time');
        $this->submitButton($client, 'Allocations');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-period"] option[value="all_time"][selected]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-scope"] option[value="public"][selected]');
    }

    public function testHospitalDataSkipsTheScopeStep(): void
    {
        $client = $this->createClientAsParticipant();

        $client->request(Request::METHOD_GET, '/statistics/analysis/assistant?scope=public');
        $this->submitButton($client, 'Distribution of a characteristic');
        $this->submitButton($client, 'Hospitals');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-step-questions"][aria-current="step"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-assistant-step-context"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-assistant-scope"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-step-filters"][aria-disabled="true"]');

        $this->submitChoices($client, 'Next', [
            'explorer_assistant[questions][row]' => 'hospital_master_cohort',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-step-summary"][aria-current="step"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-assistant-filters"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-step-filters"][aria-disabled="true"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-assistant-edit-filters"]');

        $this->submitButton($client, 'Back');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-step-questions"][aria-current="step"]');

        $this->submitButton($client, 'Back');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-step-source"][aria-current="step"]');
    }

    public function testSameCharacteristicShowsANoticeInsteadOfAProposal(): void
    {
        $client = $this->createClientAsParticipant();

        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/assistant?scope=public&period=all',
        );
        $this->submitButton($client, 'Relationship between two characteristics');
        $this->submitButton($client, 'Allocations');
        $this->submitButton($client, 'Next');

        $this->submitChoices($client, 'Next', [
            'explorer_assistant[questions][row]' => 'urgency',
            'explorer_assistant[questions][column]' => 'urgency',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-assistant-notice"]',
            'two different characteristics',
        );
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-next"]:not([disabled])');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-assistant-summary"]');
    }

    public function testHospitalStructureOffersHospitalPairsAndAHospitalTitle(): void
    {
        $client = $this->createClientAsParticipant();

        $client->request(Request::METHOD_GET, '/statistics/analysis/assistant?scope=public');
        $this->submitButton($client, 'Relationship between two characteristics');
        $this->submitButton($client, 'Hospitals');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-assistant-suggestion-1"]',
            'Location × Care tier',
        );
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-assistant-suggestion-2"]',
            'Size × Care tier',
        );
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-assistant-suggestion-4"]',
            'State × Care tier',
        );

        $this->submitButton($client, 'Location × Care tier');
        $this->submitButton($client, 'Next');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-assistant-summary-title"]',
            'Hospitals: Location × Care tier',
        );
    }

    public function testNewQueryResetsTheAssistantAndKeepsTheScopeParameters(): void
    {
        $client = $this->createClientAsParticipant();
        $client->followRedirects(false);

        $client->request(Request::METHOD_GET, '/statistics/analysis/assistant?scope=public&hospital=9&cohort=urban_basic&state=3&dispatch_area=4&period=quarter&year=2024&month=5&quarter=2&new=1');

        $this->assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/statistics/analysis/assistant', $location);
        self::assertStringNotContainsString('new=', $location);
        self::assertStringContainsString('scope=public', $location);
        self::assertStringContainsString('hospital=9', $location);
        self::assertStringContainsString('period=quarter', $location);
        self::assertStringContainsString('quarter=2', $location);
    }

    public function testUnavailableCohortRedirectsTheAssistantAndCancelToPublicScope(): void
    {
        $client = $this->createClientAsParticipant();
        $client->followRedirects(false);

        $client->request(Request::METHOD_GET, '/statistics/analysis/assistant?scope=hospital_cohort&cohort=urban_basic&period=all');

        $this->assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/statistics/analysis/assistant', $location);
        self::assertStringContainsString('scope=public', $location);
        self::assertStringNotContainsString('cohort=', $location);

        $client->request(Request::METHOD_GET, '/statistics/analysis/assistant/cancel?scope=hospital_cohort&cohort=urban_basic&period=all');

        $this->assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/statistics/analysis/assistant/cancel', $location);
        self::assertStringContainsString('scope=public', $location);
    }

    public function testAssistantAcceptsStateDispatchCohortHospitalAndMyHospitals(): void
    {
        $client = self::createClient();
        $this->seedEligibleUrbanBasicCohort($client);
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        $client->loginUser($admin);

        $entities = self::getContainer()->get(EntityManagerInterface::class);
        $state = $entities->getRepository(State::class)->findOneBy(['name' => 'Dashboard Cohort State']);
        $dispatchArea = $entities->getRepository(DispatchArea::class)->findOneBy(['name' => 'Dashboard Cohort Dispatch']);
        $hospital = $entities->getRepository(Hospital::class)->findOneBy(['name' => 'Dashboard Cohort Hospital A']);
        self::assertNotNull($state?->getId());
        self::assertNotNull($dispatchArea?->getId());
        self::assertNotNull($hospital?->getId());

        foreach ([
            '/statistics/analysis/assistant?scope=state&state='.$state->getId().'&period=all',
            '/statistics/analysis/assistant?scope=dispatch_area&dispatch_area='.$dispatchArea->getId().'&period=all',
            '/statistics/analysis/assistant?scope=hospital_cohort&cohort=urban_basic&period=all',
            '/statistics/analysis/assistant?scope=my_hospitals&period=all',
            '/statistics/analysis/assistant?scope=hospital&hospital='.$hospital->getId().'&period=all',
        ] as $url) {
            $client->request(Request::METHOD_GET, $url);
            $this->assertResponseIsSuccessful();
            $this->assertSelectorExists('[data-testid="stats-analysis-explorer-assistant-title"]');
        }
    }

    private function submitButton(KernelBrowser $client, string $label): void
    {
        $client->submit($client->getCrawler()->selectButton($label)->form());
    }

    /**
     * @param array<string, string> $choices
     */
    private function submitChoices(KernelBrowser $client, string $button, array $choices): void
    {
        $form = $client->getCrawler()->selectButton($button)->form();
        foreach ($choices as $field => $value) {
            $choice = $form[$field];
            self::assertInstanceOf(ChoiceFormField::class, $choice);
            $choice->select($value);
        }

        $client->submit($form);
    }
}
