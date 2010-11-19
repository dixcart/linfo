<?php

/**
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
 */

/**
 * Keep out hackers...
 */
defined('IN_INFO') or exit;

/**
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

	/**
	 * localize important stuff
	 * 
	 * @access public
	 */
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

	/**
	 * Deal with it
	 * 
	 * @access private
	 */
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
		if (preg_match_all('/^\s+(\d+)\*?\s+(\d+)\%\s+(\d+\.\d+ \w+|None)\s+(\w+)\s+(\d+\.\d+)\s+(\d+\.\d+)\s+(\d+\.\d+|None)\s+(\w+)\s+(.+)$/m', $result, $matches, PREG_SET_ORDER) > 0) {
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
	
	/**
	 * Do the job
	 * 
	 * @access public
	 */
	public function work() {
		$this->_call();
	}

	/**
	 * Return result
	 * 
	 * @access public
	 * @return false on failure|array of the torrents
	 */
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
				'Uploaded',
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
					array(8, 'None found')
				)
			);
		}
		else {
			
			// Store a total amount of certain torrents here:
			$status_tally = array();

			// As well as uploaded/downloaded
			$status_tally['Downloaded'] = 0;
			$status_tally['Uploaded'] = 0;

			// Go through each torrent
			foreach ($this->_torrents as $torrent) {
			
				// Status count tally
				$status_tally[$torrent['state']] = !array_key_exists($torrent['state'], $status_tally) ? 1 : $status_tally[$torrent['state']] + 1;

				// Make some sense of the have so we can get it in real units
				$have_bytes = false;
				if ($torrent['have'] != 'None') {
					$have_parts = explode(' ', $torrent['have'], 2);
					if (is_numeric($have_parts[0]) && $have_parts[0] > 0) {
						switch ($have_parts[1]) {
							case 'TiB':
								$have_bytes = (float) $have_parts[0] * 1099511627776;
							break;
							case 'GiB':
								$have_bytes = (float) $have_parts[0] * 1073741824;
							break;
							case 'MiB':
								$have_bytes = (float) $have_parts[0] * 1048576;
							break;
							case 'KiB':
								$have_bytes = (float) $have_parts[0] * 1024;
							break;
						}
					}
				}

				// Try getting amount uploaded, based upon ratio and exact amount downloaded above
				$uploaded_bytes = false;
				if (is_numeric($have_bytes) && $have_bytes > 0 && is_numeric($torrent['ratio']) && $torrent['ratio'] > 0) 
					$uploaded_bytes = $torrent['ratio'] * $have_bytes;
				
				// Save amount uploaded/downloaded tally
				if (is_numeric($have_bytes) && $have_bytes > 0 && is_numeric($uploaded_bytes) && $uploaded_bytes > 0) {
					$status_tally['Downloaded'] += $have_bytes;
					$status_tally['Uploaded'] += $uploaded_bytes;
				}
				
				// Save result
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
						$have_bytes !== false ? byte_convert($have_bytes) : $torrent['have'],
						$uploaded_bytes !== false ? byte_convert($uploaded_bytes) : 'None',
						$torrent['eta'],
						$torrent['ratio'],
						$torrent['up'] . ' / '. $torrent['down'],
					)
				);
			}

			// Finish the size totals
			$status_tally['Downloaded'] = $status_tally['Downloaded'] > 0 ? byte_convert($status_tally['Downloaded']) : 'None';
			$status_tally['Uploaded'] = $status_tally['Uploaded'] > 0 ? byte_convert($status_tally['Uploaded']) : 'None';

			// Create a row for the tally of statuses
			if (count($status_tally) > 0) {

				// Store list of k: v'ish values here
				$tally_contents = array();

				// Populate that
				foreach ($status_tally as $state => $tally)
					$tally_contents[] = "$state: $tally";

				// Save this final row
				$rows[] = array(
					'type' => 'values',
					'columns' => array(
						array(8, implode('; ', $tally_contents))
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
