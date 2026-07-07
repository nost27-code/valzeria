<?php

namespace App\Console\Commands;

use App\Support\JobArtMasterValidator;
use App\Support\JobSpecialSkillValidator;
use Illuminate\Console\Command;

class ValidateJobArts extends Command
{
    protected $signature = 'valzeria:validate-job-arts';

    protected $description = 'job_arts.json の memo（説明文）と effect_template（実際の効果種別）の食い違いを検出します。';

    public function handle(): int
    {
        $path = base_path('database/data/job_arts.json');
        if (!is_file($path)) {
            $this->error('job_arts.json が見つかりません。');
            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (!is_array($rows)) {
            $this->error('job_arts.json の読み込みに失敗しました。');
            return self::FAILURE;
        }

        $problems = JobArtMasterValidator::validateRows($rows);
        $specialRows = require base_path('database/data/job_special_skills.php');
        if (is_array($specialRows)) {
            $problems = array_merge($problems, JobSpecialSkillValidator::validateRows($specialRows));
        } else {
            $problems[] = 'job_special_skills.php の読み込みに失敗しました。';
        }

        if ($problems === []) {
            $this->info('job_arts.json / job_special_skills.php: 技説明と実効果の不整合は見つかりませんでした。');
            return self::SUCCESS;
        }

        $this->error(sprintf('技説明と実効果: %d件の不整合を検出しました。', count($problems)));
        foreach ($problems as $problem) {
            $this->line(' - ' . $problem);
        }

        return self::FAILURE;
    }
}
