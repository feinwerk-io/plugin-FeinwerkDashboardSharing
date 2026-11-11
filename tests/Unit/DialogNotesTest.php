<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\FeinwerkDashboardSharing\DialogNotes;

/**
 * The feedback question and the tip line at the end of the share dialog: one block, nothing at all when neither part
 * has content, and never an error.
 *
 * @group FeinwerkDashboardSharing
 */
class DialogNotesTest extends TestCase
{
    public function test_the_parts_in_one_block_feedback_first(): void
    {
        $this->assertSame('<div class="fwsh-notes"><b>feedback</b><i>tip</i></div>', DialogNotes::wrap('<b>feedback</b>', '<i>tip</i>'));
        $this->assertSame('<div class="fwsh-notes"><b>feedback</b></div>', DialogNotes::wrap('<b>feedback</b>', ''), 'users without admin access');
        $this->assertSame('', DialogNotes::wrap('', ''), 'no empty block');
    }

    /**
     * Whatever the environment (a running Matomo, Matomo's test runner, no container at all), the notes never throw:
     * either nothing or the one block.
     */
    public function test_never_breaks_the_dialog(): void
    {
        $html = DialogNotes::html(1);
        $this->assertTrue($html === '' || strpos($html, '<div class="fwsh-notes">') === 0, $html);
    }
}
