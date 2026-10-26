<?php

namespace Ogunsakin01\VideoDemo\Tests;

use Illuminate\Support\Facades\Process;
use Ogunsakin01\VideoDemo\Library\PlayerScripts;

/**
 * The scripts the players run in the viewer's browser. The rules that pause a
 * video while the viewer is away are tested in tests/js, with Node's own test
 * runner, when Node is installed.
 */
class PlayerScriptsTest extends TestCase
{
    public function test_the_rules_for_pausing_while_away_pass(): void
    {
        if (! Process::run(['node', '--version'])->successful()) {
            $this->markTestSkipped('Node is not installed.');
        }

        $result = Process::run(['node', '--test', ...glob(__DIR__.'/js/*.test.cjs')]);

        $this->assertTrue($result->successful(), $result->output().$result->errorOutput());
    }

    public function test_embed_js_has_the_same_script_built_in(): void
    {
        $this->assertStringContainsString('class AttentionTracker', PlayerScripts::attention());
        $this->assertStringStartsWith(PlayerScripts::attention(), PlayerScripts::embed());
        $this->assertStringContainsString("customElements.define('video-demo-player'", PlayerScripts::embed());
    }
}
