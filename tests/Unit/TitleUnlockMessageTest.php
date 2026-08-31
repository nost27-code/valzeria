<?php

namespace Tests\Unit;

use App\Models\Title;
use App\Support\TitleUnlockMessage;
use PHPUnit\Framework\TestCase;

class TitleUnlockMessageTest extends TestCase
{
    public function test_single_title_keeps_its_name_in_the_message(): void
    {
        $message = TitleUnlockMessage::forPastAchievements([
            new Title(['name' => '一人前の冒険者']),
        ]);

        $this->assertSame('過去の実績により、称号「一人前の冒険者」を獲得しました！', $message);
    }

    public function test_many_titles_are_summarized_as_one_count_message(): void
    {
        $titles = array_map(
            static fn (int $number): Title => new Title(['name' => "一括獲得称号{$number}"]),
            range(1, 196),
        );

        $message = TitleUnlockMessage::forPastAchievements($titles);

        $this->assertSame('過去の実績により、称号を196個獲得しました！', $message);
        $this->assertStringNotContainsString('一括獲得称号1', $message);
    }

    public function test_no_title_does_not_create_a_message(): void
    {
        $this->assertNull(TitleUnlockMessage::forPastAchievements([]));
    }
}
