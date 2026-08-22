<?php

namespace Tests\Unit;

use Tests\TestCase;

class ActionFeedbackAssetsTest extends TestCase
{
    public function test_standard_post_forms_and_button_links_use_shared_action_feedback(): void
    {
        $script = (string) file_get_contents(resource_path('js/app.js'));
        $styles = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('form[method="post" i]', $script);
        $this->assertStringContainsString("form.dataset.submitLock !== 'off'", $script);
        $this->assertStringContainsString('const submitLockOriginalDisabled = new WeakMap()', $script);
        $this->assertStringContainsString('payload.dataset.submitLockPayload', $script);
        $this->assertStringContainsString('event.defaultPrevented || !form.isConnected', $script);
        $this->assertStringContainsString("a[data-navigation-lock]", $script);
        $this->assertStringContainsString('event.stopImmediatePropagation()', $script);
        $this->assertStringContainsString('.is-navigation-locking', $styles);
        $this->assertStringContainsString("a[data-navigation-lock][aria-disabled='true']", $styles);
    }
}
