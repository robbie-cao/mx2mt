<?php

	class DivisionStatus {
		const START = 1;
		const BAR = 2;
		const END = 3;

		public $status;
		public $number;
	}

	class PartState {
		public $forward;
		public $durations;

		public function getDuration() {
			return array_splice($this->durations, 0, 1);
		}
	}

	class BeamState {
		public $start;
		public $heights = 0;
		public $count = 0;
		public $min;
		public $max;
	}

	class Processor {

		const NOTHING = 9999;

		const UP_LIMIT = 21;

		protected $notation;
	
		protected $division;

		protected $slant = array(
				0 => 0,
				1 => 2,
				2 => 3,
				3 => 3,
				4 => 4,
				5 => 4,
				6 => 5,
				7 => 6,
				8 => 7,
				9 => 8,
			);


		public function getNotation(Music $music) {
			$this->computeBeams($music);
			$this->computeConnections($music);
			$this->repareDoubles($music);
			$this->repareRepeats($music);

			$this->division = new DivisionStatus();
			$this->division->status = DivisionStatus::START;
			$this->division->number = 1;

			$this->notation = new Notation;
			foreach ($music->measures as $measure) {
				$parts = array_reverse($measure->parts);
				$first = reset($parts);
				$this->startVolta($first->endings);
				$this->addDivision($first->endings, $parts);
				$this->staightenMeasure($parts);
				$this->endVolta($first->endings);
			}
			$this->division->status = DivisionStatus::END;
			$this->addDivision($first->endings);
			return $this->notation;
		}


		protected function computeBeams(Music $music) {
			$measure = reset($music->measures);
			$count = count($measure->parts);
			foreach ($music->measures as $measure) {
				$maxBeam = -1;
				for ($i=0; $i<$count; $i++) {
					$part = $measure->parts[$i];
					foreach ($part->durations as $duration) {
						if (!($duration instanceof Beam)) {
							continue;
						}
						if ($duration instanceof BeamStart) {
							$state = new BeamState;
							$state->start = $duration;
							$state->min = $duration->height;
							$state->max = $duration->height;
							$maxBeam += 1;
						}
						$duration->number = $maxBeam;
						$state->heights += $duration->height;
						$state->min = min($state->min, $duration->height);
						$state->max = min($state->max, $duration->height);
						$state->count++;
						if ($duration instanceof BeamEnd) {
							$slant = $duration->height - $state->start->height;
							$sign = ($slant < 0) ? -1 : 1;
							$slant = abs($slant);
							if (array_key_exists($slant, $this->slant)) {
								$slant = $this->slant[$slant];
							} else {
								$slant = 9;
							}
							if (($state->count > 2) && ($slant >= 2)) {
								$slant--;
							}
							// TODO: min/max correction
							$state->start->slant = $slant * $sign;

							$average = $state->heights / $state->count;
							$state->start->up = ($average < self::UP_LIMIT);
							$duration->up = $state->start->up;
						}
					}
				}
			}
		}

		protected function computeConnections(Music $music) {
			$measure = reset($music->measures);
			$count = count($measure->parts);
			$under=array();
			for ($i=0; $i<$count; $i++) {
				for ($j=0; $j<=1; $j++) {
					$under[2*$i+$j] = FALSE;
				}
			}
			foreach ($music->measures as $measure) {
				foreach ($measure->parts as $pindex => $part) {
					foreach ($part->durations as $duration) {
						$number = 2 * $pindex;
						if ($duration instanceof Note) {
							$duration->nolyr = ($under[$number] || $under[$number+1]);
						}
						foreach ($duration->connections as $connection) {
							$slur = 0;
							if (($connection instanceof SlurStart) || ($connection instanceof SlurEnd)) {
								$slur = 1;
							}
							$number = 2 * $pindex + $slur;
							if ($connection instanceof ConnectionStart) {
								assert('$under[$number]===FALSE');
								$under[$number] = TRUE;
								$connection->up = ($duration->height >= self::UP_LIMIT);
							} else {
								assert('$under[$number]===TRUE');
								$under[$number] = FALSE;
							}
							$connection->number = $number;
						}
					}
				}
			}
		}

		protected function repareDoubles(Music $music) {
			$moved = new SplObjectStorage();
			foreach ($music->measures as $measure) {
				$move = array();
				foreach ($measure->parts as $pindex => $part) {
					$move[$pindex] = NULL;
					foreach ($part->endings as $index => $ending) {
						if (($ending instanceof DoubleBar) && (!$moved->contains($ending))) {
							$move[$pindex] = $ending;
							$moved->attach($ending);
							unset($part->endings[$index]);
						}
					}
				}
				if (isset($add)) {
					foreach ($measure->parts as $pindex => $part) {
						if (!is_null($add[$pindex])) {
							$part->endings[] = $add[$pindex];
						}
					}
				}
				$add = $move;
			}
		}

		protected function repareRepeats(Music $music) {
			foreach ($music->measures as $measure) {
				$move = array();
				foreach ($measure->parts as $pindex => $part) {
					$move[$pindex] = FALSE;
					foreach ($part->endings as $index => $ending) {
						if ($ending instanceof Repeat) {
							if ($ending->right) {
								$move[$pindex] = TRUE;
							}
							if ($ending->left) {
								$ending->right = FALSE;
							} else {
								unset($part->endings[$index]);
							}
						}
					}
				}
				if (isset($add)) {
					foreach ($measure->parts as $pindex => $part) {
						if ($add[$pindex]) {
							$done = FALSE;
							foreach ($part->endings as $ending) {
								if ($ending instanceof Repeat) {
									$ending->right = TRUE;
									$done = TRUE;
								}
							}
							if (!$done) {
								$ending = new Repeat;
								$ending->right = TRUE;
								$ending->left = FALSE;
								$part->endings[] = $ending;
							}
						}
					}
				}
				$add = $move;
			}
		}

		protected function addDivision(array $endings, array $parts=array()) {
			$changed = FALSE;
			$attributes = array();
			foreach ($parts as $part) {
				$attributes[] = $part->attributes;
				if (count($part->attributes) > 0) {
					$changed = TRUE;
				}
			}

			switch ($this->division->status) {
				case DivisionStatus::START:
					$division = new StartPiece;
					$division->parts = count($parts);
					$division->attributes = $attributes;
					$this->division->status = DivisionStatus::BAR;
					break;
				case DivisionStatus::BAR:
					if ($changed) {
						$division = new ChangeContext;
						$division->attributes = $attributes;
					} else {
						$division = new Bar;
					}
					break;
				case DivisionStatus::END:
					$division = new EndPiece;
					break;
			}

			$division->leftRepeat = FALSE;
			$division->rightRepeat = FALSE;
			$division->double = FALSE;
			foreach ($endings as $ending) {
				if ($ending instanceof Repeat) {
					$division->leftRepeat = $ending->left;
					$division->rightRepeat = $ending->right;
				} elseif ($ending instanceof Unsupported) {
					$this->notation->elements[] = $ending;
				} elseif ($ending instanceof DoubleBar) {
					$division->double = TRUE;
				}
			}

			$division->number = $this->division->number;
			$this->notation->elements[] = $division;
			$this->division->number++;
		}


		protected function startVolta(array $endings) {
			foreach ($endings as $ending) {
				if (($ending instanceof Volta) && ($ending->start)) {
					$this->notation->elements[] = $ending;
				}
			}
		}

		protected function endVolta(array $endings) {
			foreach ($endings as $ending) {
				if (($ending instanceof Volta) && (!$ending->start)) {
					$this->notation->elements[] = $ending;
				}
			}
		}


		protected function staightenMeasure(array $parts) {
			if (count($parts) > 1) {
				$this->staightenMultipleParts($parts);
			} else {
				$this->staightenSinglePart($parts);
			}
		}

		protected function staightenSinglePart(array $parts) {
			$part = $parts[0];
			foreach ($part->durations as $duration) {
				if ($duration instanceof Unsupported) {
					$this->notation->elements[] = $duration;
					continue;
				}
				$notes = new Notes;
				$notes->long = $duration->long;
				$notes->parts[0] = $duration;
				$this->notation->elements[] = $notes;
			}
		}

		protected function staightenMultipleParts(array $sourceParts) {
			$parts = array();
			foreach ($sourceParts as $part) {
				$state = new PartState;
				$state->forward = 0;
				$state->durations = $part->durations;
				$parts[] = $state;
			}

			$nil = new Nil;
			for($i=1; $i<=16; $i++) {
				$min = self::NOTHING;
				$result = array();
				foreach ($parts as $index => $part) {
					if ($part->forward > 0) {
						$part->forward--;
						$result[$index] = $nil;
					} else {
						$duration = $part->getDuration();
						while (!empty($duration) && ($duration instanceof Unsupported)) {
							$this->notation->elements[] = $duration;
							$duration = $part->getDuration();
						}
						if (empty($duration)) {
							$result[$index] = $nil;
						} else {
							$duration = $duration[0];
							$part->forward = $duration->long - 1;
							$result[$index] = $duration;
							if ($duration->long < $min) {
								$min = $duration->long;
							}
						}
					}
				}
				if ($min != self::NOTHING) {
					$notes = new Notes;
					$notes->long = $min;
					$notes->parts = $result;
					$this->notation->elements[] = $notes;
				}
			}
		}

	}

