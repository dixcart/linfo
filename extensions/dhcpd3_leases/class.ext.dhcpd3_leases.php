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
 * Get status on dhcp3 leases
 */
class ext_dhcpd3_leases implements LinfoExtension {

	// Minimum version of Linfo required
	const
		LINFO_MIN_VERSION = '1.5';
	
	// Where the file should be
	const 
		LEASES_FILE = '/var/lib/dhcp3/dhcpd.leases';
	
	// Store these tucked away here
	private
		$_LinfoError,
		$_res,
		$_leases = array();

	/**
	 * localize important stuff
	 * 
	 * @access public
	 */
	public function __construct() {
		$this->_LinfoError = LinfoError::Fledging();
	}

	/**
	 * Deal with it
	 * 
	 * @access private
	 */
	private function _call () {
		// Time this
		$t = new LinfoTimerStart('dhcpd3 leases extension');

		// Get contents
		$contents = getContents(self::LEASES_FILE, false);

		// Couldn't?
		if ($contents === false) {
			$this->_LinfoError->add('dhcpd3 releases extension: Error getting contents of leases file');
			$this->_res = false;
			return false;
		}

		// Get it into lines
		$lines = explode("\n", $contents);
		
		// Store temp entries here
		$curr = false;

		// Each line
		for ($i = 0, $num_lines = count($lines); $i < $num_lines; $i++) {
			
			// Potential unfucking
			$lines[$i] = trim($lines[$i]);

			// Last line in entry
			if ($lines[$i] == '}') {
				// Have we a current entry to save?
				if (is_array($curr))
					$this->_leases[] = $curr;

				// Make it empty for next time
				$curr = false;
			}

			// First line in entry. Save IP
			elseif (preg_match('/^lease (\d+\.\d+\.\d+\.\d+) \{$/', $lines[$i], $m)) {
				$curr = array('ip' => $m[1]);
			}

			// Line with lease start
			elseif ($curr && preg_match('/^starts \d+ (\d+\/\d+\/\d+ \d+:\d+:\d+);$/', $lines[$i], $m)) {
				$curr['lease_start'] = $m[1];
			}
			
			// Line with lease end
			elseif ($curr && preg_match('/^ends \d+ (\d+\/\d+\/\d+ \d+:\d+:\d+);$/', $lines[$i], $m)) {
				$curr['lease_end'] = $m[1];

				// Is this old?
				if (time() > strtotime($m[1])) {
					$curr = false;
					continue;
				}
			}
			
			// Line with MAC address
			elseif ($curr && preg_match('/^hardware ethernet (\w+:\w+:\w+:\w+:\w+:\w+);$/', $lines[$i], $m)) {
				$curr['mac'] = $m[1];
			}
			
			// [optional] Line with hostname
			elseif ($curr && preg_match('/^client\-hostname "([^"]+)";$/', $lines[$i], $m)) {
				$curr['hostname'] = $m[1];
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

		// Store rows here
		$rows = array();

		// Start showing connections
		$rows[] = array(
			'type' => 'header',
			'columns' => array(
				'IP Address',
				'MAC Address',
				'Hostname',
				'Lease Start',
				'Lease End'
			)
		);

		// Append each lease
		for ($i = 0, $num_leases = count($this->_leases); $i < $num_leases; $i++)
			$rows[] = array(
				'type' => 'values',
				'columns' => array(
					$this->_leases[$i]['ip'],
					$this->_leases[$i]['mac'],
					array_key_exists('hostname', $this->_leases[$i]) ? $this->_leases[$i]['hostname'] : '<em>unknown</em>',
					$this->_leases[$i]['lease_start'],
					$this->_leases[$i]['lease_end']
				)
			);
		
		// Give it off
		return array(
			'root_title' => 'DHCPD IP Leases',
			'rows' => $rows
		);
	}
}
