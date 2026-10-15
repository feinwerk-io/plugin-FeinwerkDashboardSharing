<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\FeinwerkDashboardSharing\LayoutSanitizer;

/**
 * The owner's layout becomes the subscriber's dashboard, and the subscriber's browser loads every widget with the
 * parameters stored in it. The copy therefore keeps only well-formed widgets and drops every parameter that would
 * change who makes the request, for which website, period or segment, or what is requested.
 *
 * @group FeinwerkDashboardSharing
 */
class LayoutSanitizerTest extends TestCase
{
    public function test_ordinaryWidgets_areKeptWithTheirOwnParameters()
    {
        $layout = $this->layout(array(array(
            $this->widget('widgetGoalsgetMetricsidGoal3', array('module' => 'Goals', 'action' => 'getMetrics', 'idGoal' => '3', 'forceView' => '1', 'viewDataTable' => 'sparklines', 'dsSegId' => 'fwsh1', 'lastMinutes' => 30, 'showtitle' => true)),
        )));

        $widget = $this->firstWidget((new LayoutSanitizer())->sanitize($layout));

        $this->assertSame('widgetGoalsgetMetricsidGoal3', $widget['uniqueId']);
        $this->assertSame(array('module' => 'Goals', 'action' => 'getMetrics', 'idGoal' => '3', 'forceView' => '1', 'viewDataTable' => 'sparklines', 'dsSegId' => 'fwsh1', 'lastMinutes' => 30, 'showtitle' => true), $widget['parameters']);
        $this->assertFalse($widget['isHidden']);
    }

    public function test_metricAndRowChoicesOfTheSeriesPicker_areKept()
    {
        $picked = array('columns' => array('nb_visits', 'nb_actions'), 'columns_to_display' => array('nb_visits', 'nb_actions'), 'rows' => array('Desktop', 'Smartphone%20App'), 'rows_to_display' => array('Desktop'), 'limits' => array(5, 10));
        $layout = $this->layout(array(array($this->widget('w1', array('module' => 'VisitsSummary', 'action' => 'getEvolutionGraph') + $picked))));

        $widget = $this->firstWidget((new LayoutSanitizer())->sanitize($layout));

        $this->assertSame(array('module' => 'VisitsSummary', 'action' => 'getEvolutionGraph') + $picked, $widget['parameters']);
    }

    public function test_parametersThatChangeIdentityWebsiteOrTarget_areDropped()
    {
        $bad = array('token_auth' => 'x', 'force_api_session' => '1', 'idSite' => '2', 'idsite' => '2', 'idSites' => '1,2', 'period' => 'year', 'date' => 'today', 'segment' => 'userId==a', 'compareSegments' => 'x', 'comparePeriods' => 'day', 'compareDates' => 'today', 'format' => 'json', 'method' => 'UsersManager.getUsers', 'callback' => 'x', 'jsoncallback' => 'x', 'url' => 'https://example.com', 'moduleToWidgetize' => 'UsersManager', 'actionToWidgetize' => 'index', 'widget' => '0', 'disableLink' => '1', 'Token_Auth' => 'y');
        $layout = $this->layout(array(array($this->widget('w1', array('module' => 'Referrers', 'action' => 'getReferrerType') + $bad))));

        $widget = $this->firstWidget((new LayoutSanitizer())->sanitize($layout));

        $this->assertSame(array('module' => 'Referrers', 'action' => 'getReferrerType'), $widget['parameters']);
    }

    public function test_malformedParameters_areDropped()
    {
        $layout = $this->layout(array(array($this->widget('w1', array(
            'module' => 'Referrers', 'action' => 'getReferrerType',
            'object' => array('k' => 'v'), 'nested' => array(array('a')), 'listWithNull' => array('a', null), 'longList' => array_fill(0, 51, 'a'),
            'listWithLongValue' => array(str_repeat('a', 300)), 'bad key' => '1', 'long' => str_repeat('a', 300), 'nothing' => null,
        )))));

        $widget = $this->firstWidget((new LayoutSanitizer())->sanitize($layout));

        $this->assertSame(array('module' => 'Referrers', 'action' => 'getReferrerType'), $widget['parameters']);
    }

    public function test_widgetsWithoutAValidModuleOrAction_areDropped()
    {
        $layout = $this->layout(array(array(
            $this->widget('w1', array('module' => '../x', 'action' => 'index')),
            $this->widget('w2', array('module' => 'CoreHome', 'action' => array('a'))),
            $this->widget('w3', array('action' => 'index')),
            array('uniqueId' => 'w4'),
            'not a widget',
            $this->widget('w5', array('module' => 'VisitsSummary', 'action' => 'getEvolutionGraph')),
        )));

        $columns = json_decode((new LayoutSanitizer())->sanitize($layout), true)['columns'];

        $this->assertSame(array('w5'), array_column($columns[0], 'uniqueId'));
    }

    public function test_extraWidgetKeys_areDroppedAndUniqueIdIsRestricted()
    {
        $layout = $this->layout(array(array(
            array('uniqueId' => 'w<img>1', 'isHidden' => 'yes', 'html' => '<script>', 'parameters' => array('module' => 'CoreHome', 'action' => 'getPromoVideo')),
        )));

        $widget = $this->firstWidget((new LayoutSanitizer())->sanitize($layout));

        $this->assertSame(array('uniqueId', 'parameters', 'isHidden'), array_keys($widget));
        $this->assertSame('wimg1', $widget['uniqueId']);
        $this->assertTrue($widget['isHidden']);
    }

    public function test_widgetsWithAReservedUniqueId_areDropped()
    {
        $layout = $this->layout(array(array(
            $this->widget('__proto__', array('module' => 'CoreHome', 'action' => 'getPromoVideo')),
            $this->widget('Constructor', array('module' => 'CoreHome', 'action' => 'getPromoVideo')),
            $this->widget('w3', array('module' => 'CoreHome', 'action' => 'getPromoVideo')),
        )));

        $columns = json_decode((new LayoutSanitizer())->sanitize($layout), true)['columns'];

        $this->assertSame(array('w3'), array_column($columns[0], 'uniqueId'));
    }

    public function test_columnLayout_isKeptWhenValidAndReplacedOtherwise()
    {
        $valid = json_decode((new LayoutSanitizer())->sanitize(json_encode(array('config' => array('layout' => '50-50'), 'columns' => array()))), true);
        $invalid = json_decode((new LayoutSanitizer())->sanitize(json_encode(array('config' => array('layout' => '<b>'), 'columns' => array()))), true);

        $this->assertSame(array('layout' => '50-50'), $valid['config']);
        $this->assertSame(array('layout' => '33-33-33'), $invalid['config']);
    }

    public function test_oldLayoutFormat_withColumnsOnly_isConverted()
    {
        $old = json_encode(array(array($this->widget('w1', array('module' => 'CoreHome', 'action' => 'getPromoVideo')))));

        $copy = json_decode((new LayoutSanitizer())->sanitize($old), true);

        $this->assertSame(array('layout' => '33-33-33'), $copy['config']);
        $this->assertSame('w1', $copy['columns'][0][0]['uniqueId']);
    }

    public function test_layoutStoredWithHtmlEntities_isReadLikeMatomoReadsIt()
    {
        $stored = htmlspecialchars($this->layout(array(array($this->widget('w1', array('module' => 'CoreHome', 'action' => 'getPromoVideo'))))), ENT_QUOTES);

        $this->assertSame('w1', $this->firstWidget((new LayoutSanitizer())->sanitize($stored))['uniqueId']);
    }

    public function test_unreadableLayout_becomesAnEmptyDashboard()
    {
        $copy = json_decode((new LayoutSanitizer())->sanitize('not json'), true);

        $this->assertSame(array('config' => array('layout' => '33-33-33'), 'columns' => array()), $copy);
    }

    private function layout(array $columns): string
    {
        return json_encode(array('config' => array('layout' => '33-33-33'), 'columns' => $columns));
    }

    private function widget(string $uniqueId, array $parameters): array
    {
        return array('uniqueId' => $uniqueId, 'parameters' => $parameters, 'isHidden' => false);
    }

    private function firstWidget(string $layout): array
    {
        return json_decode($layout, true)['columns'][0][0];
    }
}
