<?php

namespace App\Exceptions;

use RuntimeException;

/** The shared champ changed during calculation; discard the uncommitted battle. */
class ChampBattleStateChangedException extends RuntimeException {}
