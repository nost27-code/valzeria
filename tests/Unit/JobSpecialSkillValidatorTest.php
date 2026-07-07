<?php

namespace Tests\Unit;

use App\Support\JobSpecialSkillValidator;
use PHPUnit\Framework\TestCase;

class JobSpecialSkillValidatorTest extends TestCase
{
    public function test_current_job_special_skills_pass_validator(): void
    {
        $rows = require __DIR__ . '/../../database/data/job_special_skills.php';

        $this->assertSame([], JobSpecialSkillValidator::validateRows($rows));
    }

    public function test_validator_rejects_luk_description_without_structured_field(): void
    {
        $problems = JobSpecialSkillValidator::validateRows([
            [
                'job_key' => 'test_job',
                'special_name' => '検査用必殺技',
                'damage_type' => 'physical',
                'power_multiplier' => 1.5,
                'hit_count' => 1,
                'description' => '1.50倍攻撃。LUKに応じて威力上昇',
            ],
        ]);

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('luk_power_rate', implode("\n", $problems));
    }
}
