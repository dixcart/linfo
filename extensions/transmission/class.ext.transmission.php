<?php

/*
 * This file is part of Linfo (c) 2010 Joseph Gillotti.
 * 
 * Linfo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * Linfo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with Linfo.  If not, see <http://www.gnu.org/licenses/>.
 * 
*/

defined('IN_INFO') or exit; 

/*
 * Get status on transmission torrents
 */
class ext_transmission implements LinfoExtension {
	
	// Store these tucked away here
	private
		$_CallExt,
		$_LinfoError,
		$_res,
		$_torrents = array(),
		$_auth,
		$_host;

	// localize important stuff
	public function __construct() {
		global $settings;

		// Classes we need
		$this->_CallExt = new CallExt;
		$this->_CallExt->setSearchPaths(array('/usr/bin', '/usr/local/bin'));
		$this->_LinfoError = LinfoError::Fledging();

		// Transmission specific settings
		$this->_auth = array_key_exists('transmission_auth', $settings) ? (array) $settings['transmission_auth'] : array();
		$this->_host = array_key_exists('transmission_host', $settings) ? (array) $settings['transmission_host'] : array();
	}

	// Deal with it
	private function _call () {
		// Time this
		$t = new LinfoTimerStart('Transmission extension');

		// Deal with calling it
		try {
			// Start up args
			$args = '';
			
			// Specifc host/port?
			if (array_key_exists('server', $this->_host) && array_key_exists('port', $this->_host) && is_numeric($this->_host['port']))
				$args .= ' \''.$this->_host['server'].'\':'.$this->_host['port'];

			// We need some auth?
			if (array_key_exists('user', $this->_auth) && array_key_exists('pass', $this->_auth))
				$args .= ' --auth=\''.$this->_auth['user'].'\':\''.$this->_auth['pass'].'\'';

			// Rest of it, including result
			$result = $this->_CallExt->exec('transmission-remote', $args . ' -l');
		}
		catch (CallExtException $e) {
			// messed up somehow
			$this->_LinfoError->add('Transmission extension: ', $e->getMessage());
			$this->_res = false;

			// Don't bother going any further
			return false;
		}
			
		$this->_res = true;

		// Get first line
		$first_line = reset(explode("\n", $result));
		
		// Invalid host?
		if (strpos($first_line, 'Couldn\'t resolve host name') !== false) {
			$this->_LinfoError->add('Transmission extension: Invalid Host');
			$this->_res = false;
			return false;
		}

		// Invalid auth?
		if (strpos($first_line, '401: Unauthorized') !== false) {
			$this->_LinfoError->add('Transmission extension: Invalid Authentication');
			$this->_res = false;
			return false;
		}

		// Match teh torrents!
		if (preg_match_all('/^\s+(\d+)\*?\s+(\d+)\%\s+(\d+\.\d+ \w+|None)\s+(\w+)\s+(\d+\.\d+)\s+(\d+\.\d+)\s+(\d+\.\d+)\s+(\w+)\s+(.+)$/m', $result, $matches, PREG_SET_ORDER) > 0) {
			foreach ($matches as $m) {
				$this->_torrents[] = array(
					'id' => $m[1],
					'done' => $m[2],
					'have' => $m[3],
					'eta' => $m[4],
					'up' => $m[5],
					'down' => $m[6],
					'ratio' => $m[7],
					'state' => $m[8],
					'torrent' => $m[9]
				);
			}
		}
	}
	
	public function work() {
		$this->_call();
	}

	public function result() {
		// Don't bother if it didn't go well
		if ($this->_res === false) {
			return false;
		}
		// it did; continue

		// Store rows here
		$rows = array();

		// Start showing connections
		$rows[] = array(
			'type' => 'header',
			'columns' => array(
				'Torrent',
				array(1, 'Done', '10%'),
				'State',
				'Have',
				'Time Left',
				'Ratio',
				'Up/Down Speed'
			)
		);

		// No torrents?
		if (count($this->_torrents) == 0)  {
			$rows[] = array(
				'type' => 'none',
				'columns' => array(
					array(7, 'None found')
				)
			);
		}
		else {
			foreach ($this->_torrents as $torrent) {
				$rows[] = array(
					'type' => 'values',
					'columns' => array (
						wordwrap($torrent['torrent'], 50, ' ', true),
						'<div class="bar_chart">
							<div class="bar_inner" style="width: '.(int) $torrent['done'].'%;">
								<div class="bar_text">
									'.($torrent['done'] ? $torrent['done'].'%' : '0%').'
								</div>
							</div>
						</div>
						',
						$torrent['state'],
						$torrent['have'],
						$torrent['eta'],
						$torrent['ratio'],
						$torrent['up'] . ' / '. $torrent['down'],
					)
				);
			}
		}
		
		// Give it off
		return array(
			'root_title' => 'Transmission Torrents',
			'rows' => $rows
		);
	}
}
