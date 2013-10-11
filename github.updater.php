<?php
/**
* MBV GitHub Updater
*
* @package GitHub Updater
* @author Oliver Gärtner <og@mbv-media.com>
* @license GPL-3.0+
* @link http://www.mbv-media.com/
* @copyright 2013 MBV Media
*
* @wordpress-plugin
* Plugin Name: GitHub Updater
* Plugin URI: http://www.mbv-media.com/WP-Plugins/
* Description: 
* Version: 0.8.17
* Author: MBV Media | Oliver Gärtner
* Author URI: http://www.mbv-media.com/
* License: GPL-3.0+
* License URI: http://www.gnu.org/licenses/gpl-3.0.txt
*/

/**
* The GitHub-Updater class provides an interface to download WordPress plugins directly
* from GitHub into a specified folder. Normal plugins will be automatically activated and
* an include file will be automatically created for MU-Plugins.
*
* @package GitHub Updater
* @author Oliver Gärtner <og@mbv-media.com>
*/
class GitHubUpdater {
	/**
	 * Destination folder, the downloaded and unpacked GitHub repository will go here
	 *
	 * @since 0.1.0.0
	 *
	 * @var string
	 */
	private $destination_path = WP_PLUGIN_DIR;

	/**
	 * Repository owner, necessary for the GitHub API
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $repository_owner = '';

	/**
	 * Name of the repository, necessary for the GitHub API
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $repository_name = '';

	/**
	 * Name of the actual tarball file
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $file_name = '';

	/**
	 * Prefix of the GitHub API URI
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $github_api_prefix = 'https://api.github.com/repos/';

	/**
	 * Suffix of the GitHub API URI to fetch the .tar.gz
	 *
	 * @since 0.1.0
	 *
	 * @var string 
	 */
	private $github_api_suffix = '/zipball/master';

	/**
	 * Hash value of the latest commit of the repository
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $version_hash = null;

	/**
	 * If plugin exists and should only be updated this will be left on false.
	 * If it is an entierly new plugin it will be set to true.
	 * Important for certain options.
	 *
	 * @since 0.1.0
	 *
	 * @var boolean
	 */
	private $new_plugin = false;

	/**
	 * GitHub Token, should only be necessary for debugging purposes
	 *
	 * @since 0.3.3
	 *
	 * @var string
	 */
	private $github_token = null;

	/**
	 * Initialize updater, setting destination of the plugin to check and
	 * the owner and name of the repository to check and install / update.
	 * The owner and repository name can be passed separately, or combined.
	 * 
	 * e.g. developer/my-repository
	 * 
	 * The $repo argument is then no longer required.
	 *
	 * @since 0.1.0
	 * 
	 * @param string $destination Destination path for installation / update
	 * @param string $owner Owner of the Repository to install / update
	 * @param [string $repo] Repository to install / update (can be combined with owner using /)
	 */
	public function __construct($destination = null, $owner = null, $repo = null, $autoload = false, $token = null) {
		if (!empty($token) && is_string($token)) {
			$this->github_token = $token;
		}

		$this->set_destination($destination);

		$this->set_repository_owner($owner);
		$this->set_repository_name($repo);

		if ($autoload) {
			$this->update_repository();
		}
	}

	/**
	 * Set the local destination path for repositories that will be downloaded.
	 *
	 * @since 0.1.0
	 * 
	 * @param string $destination New local destination path
	 * @return boolean True if destination path was set, otherwise false
	 * 
	 * @throws Exception Type Error, if argument is not a string
	 */
	public function set_destination($destination) {
		if (!empty($destination)) {
			if (is_string($destination)) {
				// Set destination path if new value is valid
				$this->destination_path = $destination;
				return true;
			}
			else {
				throw new Exception("Type Error: Expected variable to be a string, ".gettype($destination)." given.", 101);
				return false;
			}
		}
		else {
			return false;
		}
	}

	/**
	 * Set the owner of the repository to download, necessary as part of the GitHub API request URI
	 *
	 * @since 0.1.0
	 * 
	 * @param string $owner New owner name of the GitHub repository
	 * @return boolean True if owner was set, otherwise false
	 * 
	 * @throws Exception Type Error, if argument is not a string
	 */
	public function set_repository_owner($owner) {
		if (!empty($owner)) {
			if (is_string($owner)) {
				// Set owner name if new value is valid
				$this->repository_owner = $this->check_split_owner($owner);
				return true;
			}
			else {
				throw new Exception("Type Error: Expected variable to be a string, ".gettype($owner)." given.", 101);
				return false;
			}
		}
		else {
			return false;
		}
	}

	/**
	 * Set the name of the repository to download, necessary as part of the GitHub API request URI
	 *
	 * @since 0.1.0
	 * 
	 * @param string $repo New name of the GitHub repository
	 * @return boolean True if repository name was set, otherwise false
	 * 
	 * @throws Exception Type Error, if argument is not a string
	 */
	public function set_repository_name($repo) {
		if (!empty($repo)) {
			if (is_string($repo)) {
				// Set repository name if new value is valid
				$this->repository_name = $repo;
				return true;
			}
			else {
				throw new Exception("Type Error: Expected variable to be a string, ".gettype($repo)." given.", 101);
				return false;
			}
		}
		else {
			return false;
		}
	}

	/**
	 * Download a repository, unpack it and then add it as plugin.
	 * Will activate normal plugins and create include files for mu-plugins. This function /will/ check
	 * if a plugin already exists in the respectively other plugin-directory so there won't be any
	 * clones that might cause redeclare-problems.
	 *
	 * @since 0.1.0
	 * 
	 * @throws Exception Fatal Error, if renaming of the directory fails or the plugin would be a clone
	 */
	public function update_repository() {
		// Check, if all necessary variables are set
		if (   !empty($this->destination_path) && is_string($this->destination_path)
			&& !empty($this->repository_name)  && is_string($this->repository_name)
			&& !empty($this->repository_owner) && is_string($this->repository_owner)
		) {
			$this->download_repository();

			$get_path_owner = str_replace(array(' ', '.', '_'), '-', $this->repository_owner);
			$get_path_name  = str_replace(array(' ', '.', '_'), '-', $this->repository_name);
			$get_path       = $get_path_owner.'-'.$get_path_name.'*';

			// Get path to the recently downloaded archive
			list($new_path) = glob($this->destination_path.'/'.$get_path, GLOB_ONLYDIR);

			$plugin_data = null;
			$plugin_file = null;
			$plugins_dir = @opendir($new_path);

			// Check files in new folder for a plugin header
			if ($plugins_dir) {
				while (($file = readdir($plugins_dir)) !== false) {
					if (substr($file, -4) !== '.php') {
						continue;
					}

					$plugin_file = $file;
					$plugin_data = get_plugin_data($new_path.'/'.$file);

					// Jump out of the loop once the file with the plugin header has been found
					if (is_array($plugin_data) && !empty($plugin_data['Name'])) {
						break;
					}
				}
			}

			if (!is_array($plugin_data) || empty($plugin_data['Name'])) {
				// Throw exception for now if there is no plugin header
				// TODO: Can be extended for non-plugin downloads at some point
				throw new Exception('This GitHub Project does not have a valid WordPress plugin header.', 401);
			}

			// Get actual plugin path, using the plugin name
			$new_path = str_replace($this->destination_path, '', $new_path);
			$real_path = strtolower($plugin_data['Name']);
			$real_path = sanitize_file_name($real_path);

			$github_active = get_option('github_active_plugins', array());

			// Throw an exception if the Plugin already exists in the other plugin folder and would be a clone
			// Otherwise it might try to redeclare functions or classes.
			if (   $this->destination_path === WPMU_PLUGIN_DIR
				&& in_array($real_path.'/'.$plugin_file, get_option('active_plugins'))
			) { // If MU-Plugin already exists as normal plugin
				// Remove the new plugin directory to not leave any unnecessary overhead
				$this->clear_old_directory($this->destination_path.$new_path);
				throw new Exception("Fatal Error: This plugin already exists outside the mu-plugin folder.", 305);
			}
			if ($this->destination_path === WP_PLUGIN_DIR
				 && in_array($real_path.'/'.$plugin_file, $github_active)
			) { // If normal plugin already exists as MU-Plugin
				// Remove the new plugin directory to not leave any unnecessary overhead
				$this->clear_old_directory($this->destination_path.$new_path);
				throw new Exception("Fatal Error: This plugin already exists in the mu-plugin folder.", 304);
			}

			$real_path = '/'.$real_path;

			// If the folder is not already correct rename it after removing the old folder
			if ($new_path !== $real_path) {
				$rename_ok = true;
				if (file_exists($this->destination_path.$real_path)) {
					$rename_ok = $this->clear_old_directory($this->destination_path.$real_path);
				}

				if ($rename_ok && !rename($this->destination_path.$new_path, $this->destination_path.$real_path)) {
					// Remove the new plugin directory to not leave any unnecessary overhead
					$this->clear_old_directory($this->destination_path.$new_path);
					throw new Exception("Fatal Error: Folder could not be renamed.", 303);
				}
				$new_path = $real_path;
			}

			$repo_data = $this->repository_owner.'/'.$this->repository_name;

			if ($this->destination_path === WPMU_PLUGIN_DIR) {
				// If the folder is the MU-Plugin folder create an include file in the mu-plugin folder
				$this->create_linker_file($this->destination_path.$new_path, $plugin_file, $plugin_data);

				$github_active[$repo_data] = 1;
				update_option('github_active_plugins', $github_active);
			}

			// Update list of github plugins in the options table
			$github_plugins = get_option('github_plugins', array());

			$github_plugins[$repo_data]['hash']   = $this->version_hash;
			$github_plugins[$repo_data]['file']   = $plugin_file;
			$github_plugins[$repo_data]['folder'] = $new_path;

			update_option('github_plugins', $github_plugins);

			if ($this->destination_path !== WPMU_PLUGIN_DIR) {
				// Also activate the plugin, if it is not a MU-Plugin
				activate_plugin($this->destination_path.$new_path);
			}
		}
	}

	/**
	 * Splits the owner string into owner and repository, if there is a slash present.
	 *
	 * @since 0.1.0
	 * 
	 * @param type $owner Owner string, possibly containing the repository name
	 * @return type Returns the owner without repository name
	 */
	private function check_split_owner($owner) {
		if (strpos($owner, '/') !== false) {
			// Check owner string for a slash and split it, if one exists
			list($owner, $repo) = explode('/', $owner);
		}

		// Set repository name if it was part of the owner string
		if (!empty($repo)) {
			$this->set_repository_name($repo);
		}

		return $owner;
	}

	/**
	 * Checks for the latest commit and compares the hash value with that saved in the options table.
	 * Also sets new_plugin to true if the plugin has not been added to the options before.
	 *
	 * @since 0.1.0
	 * 
	 * @return boolean Returns true if there is a new version available, otherwise false
	 * 
	 * @throws Exception GitHub Error, if reading commits of repository fails
	 */
	private function check_version() {
		$token_query = '';
		// GitHub Token for debugging purposes (for increased rate limit)
		if (!empty($this->github_token) && is_string($this->github_token)) {
			$token_query = '?access_token='.$this->github_token;
		}

		$repo_data = $this->repository_owner.'/'.$this->repository_name;
		// Fetch a list of all commits for the desired project and convert it into array of object
		$shell = curl_init($this->github_api_prefix.$repo_data.'/commits'.$token_query);
		curl_setopt($shell, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
		curl_setopt($shell, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($shell, CURLOPT_HEADER, false);

		$commits = json_decode(curl_exec($shell));

		if (is_array($commits)) {
			// Get newest commit and the corresponding hash value
			$hash_commit  = array_shift($commits);
			$this->version_hash = $hash_commit->sha;

			// Read github_plugins from the WordPress options table
			$github_plugins = get_option('github_plugins', array());
			$github_plugins = array();

			if (empty($github_plugins) || !in_array($github_plugins, $repo_data)) {
				// If plugin is not in options it is new. Set default value 0 and write it to options
				$this->new_plugin = true;

				$github_plugins[$repo_data] = array(
					'hash'   => 0,
					'folder' => '',
				);
				update_option('github_plugins', $github_plugins);

				return true;
			}
			else {
				// Plugin is not new, but maybe an update is required; compare last commit hash value
				if (    $github_plugins[$repo_data] !== $this->version_hash
					|| !is_array($github_plugins[$repo_data])
					|| !isset($github_plugins[$repo_data]['folder'])
					|| !file_exists($this->destination_path.'/'.$github_plugins[$repo_data]['folder'])
				) {
					return true;
				}
			}
		}
		elseif (is_object($commits) && !empty($commits->message)) {
			switch (strtolower($commits->message)) {
				case 'not found':
					throw new Exception("GitHub Error: Repository not found.", 202);
					break;

				default:
					throw new Exception("GitHub Error: ".$commits->message.".", 203);
			}
		}

		return false;
	}

	/**
	 * Fetch file data after redirect to actual file.
	 * Auxiliary function for repository download, in case redirects are necessary, calling
	 * itself recursively in that case.
	 * 
	 * @since 0.3.2
	 * 
	 * @param resource $shell PHP-cURL resource object
	 * @return boolean Returns true, if file was successfully created, otherwise false
	 * 
	 * @throws Exception Fatal Error, if file could not be read or written
	 */
	private function save_file_data($shell, $temp_directory) {
		if (empty($shell)) {
			throw new Exception("Type Error: Expected variable to be a resource, ".gettype($shell)." given.", 101);
		}

		// Fetch data of current iteration and split header information and file data
		$header_info = curl_exec($shell);

		// Get HTTP codes to check for redirects (301 = permanent, 302 = temporary)
		$http_code = curl_getinfo($shell, CURLINFO_HTTP_CODE);

		if ($http_code == 301 || $http_code == 302) {
			$matches = array();

			// Get line of location / uri
			if (preg_match("/(location:|uri:)[^\n]*/i", $header_info, $matches) === false) {
				throw new Exception("Fatal Error: Redirection code received, but no URL given.", 201);
			}

			// Remove everything from this line but the actual URL
			$url = trim(str_replace($matches[1], "", $matches[0]));
			$url_parsed = parse_url($url);

			// If URL is valid
			if (isset($url_parsed)) {
				// Start new iteration with new, redirected URL
				curl_setopt($shell, CURLOPT_URL, $url);
				return $this->save_file_data($shell, $temp_directory);
			}
		}

		$file_name = null;

		// When file is returned extract filename from the header information
		preg_match('/filename=([^\n]+)/', $header_info, $file_name);
		$this->file_name = trim(array_pop($file_name));
		
		$file = fopen($temp_directory.$this->file_name, "w+");

		if ($file === false) {
			throw new Exception("Fatal Error: File pointer could not be created.", 301);
		}

		// Switch cURL to receive the file body instead of header information
		curl_setopt($shell, CURLOPT_HEADER, false);
		curl_setopt($shell, CURLOPT_NOBODY, false);
		curl_setopt($shell, CURLOPT_FILE, $file);

		$header_info = curl_exec($shell);
		fclose($file);

		if (   file_exists($temp_directory.$this->file_name)
			&& is_file($temp_directory.$this->file_name)
			&& filesize($temp_directory.$this->file_name) > 0
		) {
			return true;
		}
		else {
			// Remove file if writing operation was not successful
			unlink($temp_directory.$this->file_name);
			return false;
		}
    }

	/**
	 * Download the archive with help of an auxiliary function and then unpack it
	 * into the specified destination folder
	 *
	 * @since 0.1.0
	 * 
	 * @throws Exception Fatal Error, if unpacking the zip archive was not successful
	 */
	private function download_repository() {
		if ($this->check_version()) {
			// Create cURL reference for tarball file
			$repo_data = $this->repository_owner.'/'.$this->repository_name;
			$shell = curl_init($this->github_api_prefix.$repo_data.$this->github_api_suffix);

			// Set options to receive file headers
			curl_setopt($shell, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
			curl_setopt($shell, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($shell, CURLOPT_ENCODING, '');
			curl_setopt($shell, CURLOPT_HEADER, true);
			curl_setopt($shell, CURLOPT_NOBODY, true);

			// GitHub Token for debugging purposes (for increased rate limit)
			if (!empty($this->github_token) && is_string($this->github_token)) {
				curl_setopt($shell, CURLOPT_HTTPHEADER, array(
					'Authorization: token '.$this->github_token,
				));
			}

			$upload_dir = wp_upload_dir();
			// Files will be saved to the uploads directory, into the sub-directory /temp
			$temp_directory = $upload_dir['basedir'].'/temp/';
			
			// Create temp_directory folder if it does not yet exist
			if (!file_exists($temp_directory)) {
				mkdir($temp_directory);
			}
			
			if (!file_exists($temp_directory)) {
				// Stop executing function if there's no file name or it failed to create the folder
				exit(__('Unable to download MU Plugin: No temp directory', 'mbv-newsletter'));
				return;
			}

			if ($this->save_file_data($shell, $temp_directory)) {
				if (file_exists($temp_directory.$this->file_name)) {
					// Unpack zip archive using the ZipArchive class
					//	- This was somehow not possible with Phar, as every single file was
					//	  still encoded after unpacking, despite calling $phar->decompress()
					//	  before $phar->extractTo(), neither with the types PHAR::ZIP nor
					//	  PHAR::BZ2 or PHAR:GZ
					//	- This was also not possible using a tarball download, as Phar always
					//	  threw the exception that one file in the archive (pax_global_header)
					//	  returned the wrong checksum when trying to read the archive
					$zip_archive = new ZipArchive;
					$zip_archive->open($temp_directory.$this->file_name);
					$zip_archive->extractTo($this->destination_path);
					$zip_archive->close();

					// Remove unnecessary files after extraction
					unlink($temp_directory.$this->file_name);
					return true;
				}
				else {
					unlink($temp_directory.$this->file_name);
					throw new Exception("Fatal Error: File could not be unpacked.", 302);
				}
			}
		}
	}

	/**
	 * Will remove a directory before the new plugin sub-directory will be renamed to replace it
	 * when an update is available.
	 * 
	 * @since 0.8.0
	 * 
	 * @param string $dir_to_clear Directory that will be replaced and has to be removed before renaming
	 * @return boolean Returns true, if the old folder could be removed, otherwise false
	 */
	private function clear_old_directory($dir_to_clear) {
		$rdi   = new RecursiveDirectoryIterator($dir_to_clear, RecursiveDirectoryIterator::SKIP_DOTS);
		$files = new RecursiveIteratorIterator($rdi, RecursiveIteratorIterator::CHILD_FIRST);

		foreach ($files as $fileinfo) {
			$do_this = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
			$do_this($fileinfo->getPathname());
		}

		if (file_exists($dir_to_clear)) {
			return rmdir($dir_to_clear);
		}
		else {
			true;
		}
	}

	/**
	 * Creates a file that includes teh actual plugin main file
	 * Will only be called if the plugin is a MU-Plugin, as WP only checks the folder itself for
	 * files to include, not any of the subfolders inside it.
	 * 
	 * @since 0.8.0
	 * 
	 * @param string $plugin_path Path where the plugin main file can be found. Used to name the include function
	 * @param string $plugin_file File name of the plugin main file
	 */
	private function create_linker_file($plugin_path, $plugin_file, $plugin_info = null) {
		$linker = fopen($plugin_path.'.php', 'w+');
		$real_dir = str_replace(WPMU_PLUGIN_DIR, '', $plugin_path);

		$header = '';
		if (!empty($plugin_info)) {
			$header = "
/**
 * ".$plugin_file." - Linker-File
 *
 * Plugin Name: ".$plugin_info['Name']."
 * Plugin URI:  ".$plugin_info['PluginURI']."
 * Description: ".$plugin_info['Description']."
 * Version:     ".$plugin_info['Version']."
 * Author:      ".$plugin_info['Author']."
 * Author URI:  ".$plugin_info['AuthorURI']."
 */";
		}

		fwrite($linker, "<?php".$header."\ninclude_once(WPMU_PLUGIN_DIR.'".$real_dir."/".$plugin_file."');\n?>");

		fclose($linker);
	}
}
?>