<?php

	class Creator {

		protected $text = '';

		private $pitches = array();

		protected $notes = array(
				1  => '\notes',
				2  => '\Notes',
				3  => '\Notesp',
				4  => '\NOtes',
				6  => '\NOtesp',
				8  => '\NOTes',
				12 => '\NOTesp',
				16 => '\NOTEs',
				24 => '\NOTEs',
			);

		protected $note = array(
				1  => '\cca',
				2  => '\ca',
				3  => '\cap',
				4  => '\qa',
				6  => '\qap',
				8  => '\ha',
				12 => '\hap',
				16 => '\wh',
				24 => '\whp',
			);

		protected $znote = array(
				1  => '\zqp',
				2  => '\zq',
				3  => '\zqp',
				4  => '\zq',
				6  => '\zqp',
				8  => '\zh',
				12 => '\zhp',
				16 => '\zwh',
				24 => '\zwhp',
			);

		protected $pause = array(
				1  => '\qs',
				2  => '\ds',
				3  => '\dsp',
				4  => '\qp',
				6  => '\qpp',
				8  => '\hpause',
				12 => '\hpausep',
				16 => '\pause',
				24 => '\pausep',
			);


		public function __construct() {
			$upper = ord('C');
			$lower = ord('a');
			for($i=1; $i<=35; $i++) {
				if ($i <= 12) {
					$this->pitches[$i] = chr($upper);
					$upper++;
				} else {
					$this->pitches[$i] = chr($lower);
					$lower++;
				}
			}
		}

		public function getTex(Notation $notation) {
			$this->text = '';
			$this->writeBegin();
			foreach ($notation->elements as $element) {
				$name = get_class($element);
				switch ($name) {
					case 'Notes':
						$this->writeNotes($element);
						break;
					case 'Volta':
						$this->writeVolta($element);
						break;
					case 'StartPiece':
						$this->writeStartPiece($element);
						break;
					case 'Bar':
						$this->writeBar($element);
						break;
					case 'ChangeContext':
						$this->writeChangeContext($element);
						break;
					case 'EndPiece':
						$this->writeEndPiece($element);
						break;
					case 'Unsupported':
						$this->writeUnsupported($element);
						break;
				}
			}
			$this->writeEnd();
			return $this->text;
		}


		protected function writeBegin() {
			$this->add('\input header');
			$this->add('');
		}

		protected function writeEnd() {
			$this->add('');
			$this->add('\bye');
		}

		protected function writeNotes(Notes $notes) {
			$line = $this->notes[$notes->long];
			$parts = array();
			$count = count($notes->parts);
			$i = 1;
			foreach ($notes->parts as $part) {
				$name = get_class($part);
				$treat = TRUE;
				switch ($name) {
					case 'Pause':
						$fragment = $this->getPause($part);
						$treat = FALSE;
						break;
					case 'Note':
						$fragment = $this->getNote($part);
						break;
					case 'BeamStart':
						$fragment = $this->getBeamStart($part);
						break;
					case 'BeamContinue':
						$fragment = $this->getBeamContinue($part);
						break;
					case 'BeamEnd':
						$fragment = $this->getBeamEnd($part);
						break;
					case 'Nil':
						$treat = FALSE;
						$fragment = '';
				}
				if ($treat && ($i != $count)) {
					$fragment .= '%';
				}
				$parts[] = $fragment;
				$i++;
			}
			$line .= implode("\n\t&", $parts);
			$line .= '\en';
			$this->add($line);
		}

		protected function writeVolta(Volta $volta) {
			if ($volta->start) {
				$this->add('\Setvolta'.$this->nr($volta->number).'%');
			} else {
				$this->add('\setendvolta');
			}
		}

		protected function writeStartPiece(StartPiece $start) {
			$this->add('\setvoices{'.$start->parts.'}');
			$this->writeAttributes($start->attributes);
			$this->add('');
			$this->add('\Startpiece');
			$this->add('% '.$start->number);
			if ($start->leftRepeat) {
				$this->add('\leftrepeat');
				// TODO: \advance\barno by-1
			}
		}

		protected function writeBar(Bar $bar) {
			if ($bar->leftRepeat && $bar->rightRepeat) {
				$this->add('\leftrightrepeat');
			} elseif ($bar->leftRepeat) {
				$this->add('\leftrepeat');
			} elseif ($bar->rightRepeat) {
				$this->add('\rightrepeat');
			} elseif ($bar->double) {
				$this->add('\doublebar');
			} else {
				$this->add('\bar');
			}
			$this->add('% '.$bar->number);
		}

		protected function writeChangeContext(ChangeContext $context) {
			if ($context->leftRepeat && $context->rightRepeat) {
				$this->add('\setleftrightrepeat');
			} elseif ($context->leftRepeat) {
				$this->add('\setleftrepeat');
			} elseif ($context->rightRepeat) {
				$this->add('\setrightrepeat');
			} elseif ($context->double) {
				$this->add('\setdoublebar');
			}
			$this->writeAttributes($context->attributes);
			$this->add('\changecontext');
			$this->add('% '.$context->number);
		}

		protected function writeEndPiece(EndPiece $end) {
			if ($end->rightRepeat) {
				$this->add('\setrightrepeat');
			}
			$this->add('\Endpiece');
		}

		protected function writeAttributes(array $parts) {
			$singleMeter = TRUE;
			$singleSignature = TRUE;
			$firstMeter = NULL;
			$firstSignature = NULL;
			foreach ($parts as $index => $part) {
				$meter = NULL;
				$signature = NULL;
				foreach ($part as $attribute) {
					$name = get_class($attribute);
					switch ($name) {
						case 'Meter':
							if ($index == 0) {
								$firstMeter = $attribute;
							}
							$meter = $attribute;
							break;
						case 'Signature':
							if ($index == 0) {
								$firstSignature = $attribute;
							}
							$signature = $attribute;
							break;
					}
				}
				$singleMeter = ($singleMeter && ($meter == $firstMeter));
				$singleSignature = ($singleSignature && ($signature == $firstSignature));
			}

			if ($singleMeter) {
				if (!is_null($firstMeter)) {
					$this->writeMeter(0, $firstMeter);
				}
			} else {
				foreach ($parts as $index => $part) {
					foreach ($part as $attribute) {
						if ($attribute instanceof Meter) {
							$this->writeMeter($index+1, $attribute);
						}
					}
				}
			}
			if ($singleSignature) {
				if (!is_null($firstSignature)) {
					$this->writeSignature(0, $firstSignature);
				}
			} else {
				foreach ($parts as $index => $part) {
					foreach ($part as $attribute) {
						if ($attribute instanceof Signature) {
							$this->writeSignature($index+1, $attribute);
						}
					}
				}
			}
		}

		protected function writeMeter($part, Meter $meter) {
			$type = '{\meterfrac{'.$meter->beats.'}{'.$meter->unit.'}}';
			if ($part == 0) {
				$this->add('\generalmeter'.$type.'%');
			} else {
				$this->add('\setmeter'.$this->nr($part).'{'.$type.'}%');
			}
		}

		protected function writeSignature($part, Signature $signature) {
			if ($part == 0) {
				$this->add('\generalsignature{'.$signature->fifths.'}%');
			} else {
				$this->add('\setsign'.$this->nr($part).'{'.$signature->fifths.'}%');
			}
		}

		protected function writeUnsupported(Unsupported $unsupported) {
			$this->add('% ===== XXX: '.$unsupported->info.' =====');
		}


		protected function getPause(Pause $pause) {
			return $this->pause[$pause->long];
		}

		protected function getNote(Note $note) {
			$nl = $this->nl($note->nolyr);
			$conn = $this->getConnections($note);
			$lng = $this->note[$note->long];
			$hta = $this->hta($note, ' ');
			$znotes = $this->getZNotes($note);
			return $nl.$conn.$znotes.$lng.$hta;
		}

		protected function getBeamStart(BeamStart $beam) {
			$nl = $this->nl($beam->nolyr);
			$conn = $this->getConnections($beam);
			$bb = $this->bb($beam->multiplicity);
			$bu = $this->bu($beam->up);
			$nr = $this->nr($beam->number);
			$sl = $this->nr($beam->slant);
			$ht = $this->ht($beam);
			$hta = $this->hta($beam);
			$pt = $this->pt($beam->long);
			$partial = $this->partial($beam->partial, $nr, $bu);
			$znotes = $this->getZBeams($beam, $nr);
			return $nl.$conn.'\i'.$bb.$bu.$nr.$ht.$sl.$partial.$znotes.'\qb'.$pt.$nr.$hta;
		}

		protected function getBeamContinue(BeamContinue $beam) {
			$nl = $this->nl($beam->nolyr);
			$conn = $this->getConnections($beam);
			$bu = $this->bu($beam->up);
			$nr = $this->nr($beam->number);
			$change = $this->change($beam->change, $nr, $bu);
			$hta = $this->hta($beam);
			$pt = $this->pt($beam->long);
			$partial = $this->partial($beam->partial, $nr, $bu);
			$znotes = $this->getZBeams($beam, $nr);
			return $nl.$conn.$change.$partial.$znotes.'\qb'.$pt.$nr.$hta;
		}

		protected function getBeamEnd(BeamEnd $beam) {
			$nl = $this->nl($beam->nolyr);
			$conn = $this->getConnections($beam);
			$bb = $this->bb($beam->multiplicity);
			$bu = $this->bu($beam->up);
			$nr = $this->nr($beam->number);
			$hta = $this->hta($beam);
			$pt = $this->pt($beam->long);
			$partial = $this->partial($beam->partial, $nr, $bu);
			$znotes = $this->getZBeams($beam, $nr);
			return $nl.$conn.$partial.'\tb'.$bu.$nr.$znotes.'\qb'.$pt.$nr.$hta;
		}

		protected function getConnections(Duration $note) {
			$connections = '';
			$ht = $this->ht($note);
			foreach ($note->connections as $connection) {
				$name = get_class($connection);
				$nr = $this->nr($connection->number);
				switch ($name) {
					case 'SlurStart':
						$cu = $this->cu($connection->up);
						$connections .= '\islur'.$cu.$nr.$ht;
						break;
					case 'SlurEnd':
						$connections .= '\tslur'.$nr.$ht;
						break;
					case 'TieStart':
						$cu = $this->cu($connection->up);
						$connections .= '\itie'.$cu.$nr.$ht;
						break;
					case 'TieEnd':
						$connections .= '\ttie'.$nr;
						break;
				}
			}
			foreach ($note->chords as $chord) {
				$connections .= $this->getConnections($chord);
			}
			return $connections;
		}

		protected function getZNotes(Note $note) {
			$result = '';
			foreach ($note->chords as $chord) {
				$lng = $this->znote[$chord->long];
				$hta = $this->hta($chord, ' ');
				$result .= $lng.$hta;
			}
			return $result;
		}

		protected function getZBeams(Beam $note, $nr) {
			$result = '';
			foreach ($note->chords as $chord) {
				$lng = '\zqb';
				$pt = $this->pt($chord->long);
				$hta = $this->hta($chord);
				$result .= $lng.$pt.$nr.$hta;
			}
			return $result;
		}

		protected function change($change, $nr, $bu) {
			if ($change > 0) {
				return '\tbb'.$bu.$nr;
			}
			if ($change < 0) {
				return '\nbb'.$bu.$nr;
			}
			return '';
		}

		protected function partial($partial, $nr, $bu) {
			if ($partial > 0) {
				return '\roff{\tbb'.$bu.$nr.'}';
			}
			if ($partial < 0) {
				return '\tbb'.$bu.$nr;
			}
			return '';
		}

		protected function ht(Note $note) {
			return $this->pitches[$note->height];
		}

		protected function hta(Note $note, $addit='') {
			$height = $this->ht($note);
			if (is_null($note->alter)) {
				return $addit.$height;
			}
			switch ($note->alter) {
				case -2:
					$height = '<'.$height;
					break;
				case -1:
					$height = '_'.$height;
					break;
				case 0:
					$height = '='.$height;
					break;
				case 1:
					$height = '^'.$height;
					break;
				case 2:
					$height = '>'.$height;
					break;
			}
			return '{'.$height.'}';
		}

		protected function nl($nolyr) {
			return $nolyr ? '\nolyr' : '';
		}

		protected function nr($number) {
			return ($number>=0 && $number<=9) ? $number : '{'.$number.'}' ;
		}

		protected function bb($count) {
			return str_repeat('b', $count);
		}

		protected function bu($up) {
			return $up ? 'u' : 'l';
		}

		protected function cu($up) {
			return $up ? 'u' : 'd';
		}

		protected function pt($long) {
			return (($long != 1) && ($long % 2 == 1)) ? 'p' : '';
		}

		protected function add($line) {
			$this->text .= $line."\n";
		}

	}

