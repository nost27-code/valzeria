<?php
namespace App\Services {
    function random_int(int $min, int $max): int { return isset($GLOBALS['sixHeroReplayRng']) ? $GLOBALS['sixHeroReplayRng']->getInt($min, $max) : \random_int($min, $max); }
    function rand(int $min, int $max): int { return isset($GLOBALS['sixHeroReplayRng']) ? $GLOBALS['sixHeroReplayRng']->getInt($min, $max) : \rand($min, $max); }
}
namespace App\Services\Battle {
    function random_int(int $min, int $max): int { return isset($GLOBALS['sixHeroReplayRng']) ? $GLOBALS['sixHeroReplayRng']->getInt($min, $max) : \random_int($min, $max); }
    function rand(int $min, int $max): int { return isset($GLOBALS['sixHeroReplayRng']) ? $GLOBALS['sixHeroReplayRng']->getInt($min, $max) : \rand($min, $max); }
}
