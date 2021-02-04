<?php

if ( !function_exists( 'Markdown' ) ) {
	include 'markdown.php'; //Used to convert readme.txt contents to HTML.
}

if ( ! class_exists( 'RocketGeek_WordPress_Parser' ) ):
class RocketGeek_WordPress_Parser {
	/**
	 * Extract headers and readme.txt data from a ZIP archive that contains a plugin or theme.
	 *
	 * Returns an associative array with these keys:
	 *  'type'   - Detected package type. This can be either "plugin" or "theme".
	 * 	'header' - An array of plugin or theme headers. See get_plugin_data() or WP_Theme for details.
	 *  'readme' - An array of metadata extracted from readme.txt. @see self::parse_readme()
	 * 	'plugin_file' - The name of the PHP file where the plugin headers were found relative to the root directory of the ZIP archive.
	 * 	'stylesheet' - The relative path to the style.css file that contains theme headers, if any.
	 *
	 * The 'readme' key will only be present if the input archive contains a readme.txt file
	 * formatted according to WordPress.org readme standards. Similarly, 'plugin_file' and
	 * 'stylesheet' will only be present if the archive contains a plugin or a theme, respectively.
	 *
	 * @param   string  $package_file_name  The path to the ZIP package.
	 * @param   bool    $apply_markdown     Whether to transform markup used in readme.txt to HTML. Defaults to false.
	 * @return  array                       Either an associative array or FALSE if the input file is not a valid ZIP archive or doesn't contain a WP plugin or theme.
	 */
	public static function parse_package( $package_file_name, $apply_markdown = false ) {
		if ( ! file_exists( $package_file_name ) || ! is_readable( $package_file_name ) ) {
			return false;
		}

		//Open the .zip
		$zip = new ZipArchive();
		if ( $zip->open( $package_file_name ) !== true ) {
			return false;
		}

		//Find and parse the plugin or theme file and (optionally) readme.txt.
		$header      = null;
		$readme      = null;
		$plugin_file = null;
		$stylesheet  = null;
		$type        = null;

		for ( $file_index = 0; ( $file_index < $zip->numFiles ) && ( empty( $readme ) || empty( $header ) ); $file_index++ ) {
			$info = $zip->statIndex( $file_index );

			//Normalize file_name: convert backslashes to slashes, remove leading slashes.
			$file_name = trim ( str_replace( '\\', '/', $info['name'] ), '/' );
			$file_name = ltrim( $file_name, '/' );

			$tmp       = explode( '.', $file_name );
			$extension = strtolower( end( $tmp ) );
			$depth     = substr_count( $file_name, '/' );

			//Skip empty files, directories and everything that's more than 1 sub-directory deep.
			if ( ( $depth > 1 ) || ( $info['size'] == 0 ) ) {
				continue;
			}

			//readme.txt (for plugins )?
			if ( empty( $readme ) && ( strtolower( basename( $file_name ) ) == 'readme.txt' ) ) {
				//Try to parse the readme.
				$readme = self::parse_readme( $zip->getFromIndex( $file_index ), $apply_markdown );
			}

			//Theme stylesheet?
			if ( empty( $header ) && ( strtolower( basename( $file_name ) ) == 'style.css' ) ) {
				$file_contents = substr( $zip->getFromIndex( $file_index ), 0, 8*1024 );
				$header = self::get_theme_headers( $file_contents );
				if ( ! empty( $header ) ) {
					$stylesheet = $file_name;
					$type = 'theme';
				}
			}

			//Main plugin file?
			if ( empty( $header ) && ( $extension === 'php' ) ) {
				$file_contents = substr( $zip->getFromIndex( $file_index ), 0, 8*1024 );
				$header = self::get_plugin_headers( $file_contents );
				if ( ! empty( $header ) ) {
					$plugin_file = $file_name;
					$type = 'plugin';
				}
			}
		}

		if ( empty( $type ) ) {
			return false;
		} else {
			return compact( 'header', 'readme', 'plugin_file', 'stylesheet', 'type' );
		}
	}

	/**
	 * Parse a plugin's readme.txt to extract various plugin metadata.
	 *
	 * Returns an array with the following fields:
	 * 	'name' - Name of the plugin.
	 * 	'contributors' - An array of wordpress.org usernames.
	 * 	'donate' - The plugin's donation link.
	 * 	'tags' - An array of the plugin's tags.
	 * 	'requires' - The minimum version of WordPress that the plugin will run on.
	 * 	'tested' - The latest version of WordPress that the plugin has been tested on.
	 * 	'stable' - The SVN tag of the latest stable release, or 'trunk'.
	 * 	'short_description' - The plugin's "short description".
	 * 	'sections' - An associative array of sections present in the readme.txt.
	 *               Case and formatting of section headers will be preserved.
	 *
	 * Be warned that this function does *not* perfectly emulate the way that WordPress.org
	 * parses plugin readme's. In particular, it may mangle certain HTML markup that wp.org
	 * handles correctly.
	 *
	 * @see http://wordpress.org/extend/plugins/about/readme.txt
	 *
	 * @param   string      $readme_txt_contents  The contents of a plugin's readme.txt file.
	 * @param   bool        $apply_markdown       Whether to transform Markdown used in readme.txt sections to HTML. Defaults to false.
	 * @return  array|null                        Associative array, or NULL if the input isn't a valid readme.txt file.
	 */
	public static function parse_readme( $readme_txt_contents, $apply_markdown = false ) {
		$readme_txt_contents = trim( $readme_txt_contents, " \t\n\r" );
		$readme = array(
			'name'              => '',
			'contributors'      => array(),
			'donate'            => '',
			'tags'              => array(),
			'requires'          => '',
			'tested'            => '',
			'stable'            => '',
			'short_description' => '',
			'sections'          => array(),
		);

		//The readme.txt header has a fairly fixed structure, so we can parse it line-by-line
		$lines = explode( "\n", $readme_txt_contents );
		//Plugin name is at the very top, e.g. === My Plugin ===
		if ( preg_match( '@===\s*(.+?)\s*===@', array_shift( $lines ), $matches ) ) {
			$readme['name'] = $matches[1];
		} else {
			return null;
		}

		//Then there's a bunch of meta fields formatted as "Field: value"
		$headers = array();
		$header_map = array(
			'Contributors'      => 'contributors',
			'Donate link'       => 'donate',
			'Tags'              => 'tags',
			'Requires at least' => 'requires',
			'Tested up to'      => 'tested',
			'Stable tag'        => 'stable',
		);
		do { //Parse each readme.txt header
			$pieces = explode( ':', array_shift( $lines ), 2 );
			if ( array_key_exists( $pieces[0], $header_map ) ) {
				if ( isset( $pieces[1] ) ) {
					$headers[ $header_map[ $pieces[0] ] ] = trim( $pieces[1] );
				} else {
					$headers[ $header_map[ $pieces[0] ] ] = '';
				}
			}
		} while ( trim( $pieces[0] ) != '' ); //Until an empty line is encountered

		//"Contributors" is a comma-separated list. Convert it to an array.
		if ( ! empty( $headers['contributors'] ) ) {
			$headers['contributors'] = array_map( 'trim', explode( ',', $headers['contributors'] ) );
		}

		//Likewise for "Tags"
		if ( ! empty( $headers['tags'] ) ) {
			$headers['tags'] = array_map( 'trim', explode( ',', $headers['tags'] ) );
		}

		$readme = array_merge( $readme, $headers );

		//After the headers comes the short description
		$readme['short_description'] = array_shift( $lines );

		//Finally, a valid readme.txt also contains one or more "sections" identified by "== Section Name =="
		$sections = array();
		$content_buffer = array();
		$current_section = '';
		foreach( $lines as $line ) {
			//Is this a section header?
			if ( preg_match( '@^\s*==\s+(.+?)\s+==\s*$@m', $line, $matches ) ) {
				//Flush the content buffer for the previous section, if any
				if ( ! empty( $current_section ) ) {
					$section_content = trim( implode( "\n", $content_buffer ) );
					$sections[ $current_section] = $section_content;
				}
				//Start reading a new section
				$current_section = $matches[1];
				$content_buffer = array();
			} else {
				//Buffer all section content
				$content_buffer[] = $line;
			}
		}
		//Flush the buffer for the last section
		if ( ! empty( $current_section ) ) {
			$sections[ $current_section] = trim( implode( "\n", $content_buffer ) );
		}

		//Apply Markdown to sections
		if ( $apply_markdown ) {
			$sections = array_map( __CLASS__ . '::apply_markdown', $sections );
		}

		//This is only necessary if you intend to later json_encode() the sections.
		//json_encode() may encode certain strings as NULL if they're not in UTF-8.
		$sections = array_map( 'utf8_encode', $sections );

		$readme['sections'] = $sections;

		return $readme;
	}

	/**
	 * Transform Markdown markup to HTML.
	 *
	 * Tries ( in vain ) to emulate the transformation that WordPress.org applies to readme.txt files.
	 *
	 * @param string $text
	 * @return string
	 */
	private static function apply_markdown( $text ) {
		//The WP standard for readme files uses some custom markup, like "= H4 headers ="
		$text = preg_replace( '@^\s*=\s*(.+?)\s*=\s*$@m', "<h4>$1</h4>\n", $text );
		return Markdown( $text );
	}

	/**
	 * Parse the plugin contents to retrieve plugin's metadata headers.
	 *
	 * Adapted from the get_plugin_data() function used by WordPress.
	 * Returns an array that contains the following:
	 *		'Name' - Name of the plugin.
	 *		'Title' - Title of the plugin and the link to the plugin's web site.
	 *		'Description' - Description of what the plugin does and/or notes from the author.
	 *		'Author' - The author's name.
	 *		'AuthorURI' - The author's web site address.
	 *		'Version' - The plugin version number.
	 *		'PluginURI' - Plugin web site address.
	 *		'TextDomain' - Plugin's text domain for localization.
	 *		'DomainPath' - Plugin's relative directory path to .mo files.
	 *		'Network' - Boolean. Whether the plugin can only be activated network wide.
	 *
	 * If the input string doesn't appear to contain a valid plugin header, the function
	 * will return NULL.
	 *
	 * @param   string     $file_contents  Contents of the plugin file
	 * @return  array|null                 See above for description.
	 */
	public static function get_plugin_headers( $file_contents ) {
		//[Internal name => Name used in the plugin file]
		$pluginHeaderNames = array(
			'Name'        => 'Plugin Name',
			'PluginURI'   => 'Plugin URI',
			'Version'     => 'Version',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'TextDomain'  => 'Text Domain',
			'DomainPath'  => 'Domain Path',
			'Network'     => 'Network',
			//Site Wide Only is deprecated in favor of Network.
			'_sitewide'   => 'Site Wide Only',
		);

		$headers = self::get_file_headers( $file_contents, $pluginHeaderNames );

		//Site Wide Only is the old header for Network.
		if ( empty( $headers['Network'] ) && ! empty( $headers['_sitewide'] ) ) {
			$headers['Network'] = $headers['_sitewide'];
		}
		unset( $headers['_sitewide'] );
		$headers['Network'] = ( strtolower( $headers['Network'] ) === 'true' );

		//For backward compatibility by default Title is the same as Name.
		$headers['Title'] = $headers['Name'];

		//If it doesn't have a name, it's probably not a plugin.
		if ( empty( $headers['Name'] ) ) {
			return null;
		} else {
			return $headers;
		}
	}

	/**
	 * Parse the theme stylesheet to retrieve its metadata headers.
	 *
	 * Adapted from the get_theme_data() function and the WP_Theme class in WordPress.
	 * Returns an array that contains the following:
	 *		'Name' - Name of the theme.
	 *		'Description' - Theme description.
	 *		'Author' - The author's name
	 *		'AuthorURI' - The authors web site address.
	 *		'Version' - The theme version number.
	 *		'ThemeURI' - Theme web site address.
	 *		'Template' - The slug of the parent theme. Only applies to child themes.
	 *		'Status' - Unknown. Included for completeness.
	 *		'Tags' - An array of tags.
	 *		'TextDomain' - Theme's text domain for localization.
	 *		'DomainPath' - Theme's relative directory path to .mo files.
	 *
	 * If the input string doesn't appear to contain a valid theme header, the function
	 * will return NULL.
	 *
	 * @param   string      $file_contents  Contents of the theme stylesheet.
	 * @return  array|null                  See above for description.
	 */
	public static function get_theme_headers( $file_contents ) {
		$theme_header_names = array(
			'Name'        => 'Theme Name',
			'ThemeURI'    => 'Theme URI',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'Version'     => 'Version',
			'Template'    => 'Template',
			'Status'      => 'Status',
			'Tags'        => 'Tags',
			'TextDomain'  => 'Text Domain',
			'DomainPath'  => 'Domain Path',
			'DetailsURI'  => 'Details URI',
		);
		$headers = self::get_file_headers( $file_contents, $theme_header_names );

		$headers['Tags'] = array_filter( array_map( 'trim', explode( ',', strip_tags( $headers['Tags'] ) ) ) );

		//If it doesn't have a name, it's probably not a valid theme.
		if ( empty( $headers['Name'] ) ) {
			return null;
		} else {
			return $headers;
		}
	}

	/**
	 * Parse the file contents to retrieve its metadata.
	 *
	 * Searches for metadata for a file, such as a plugin or theme.  Each piece of
	 * metadata must be on its own line. For a field spanning multiple lines, it
	 * must not have any newlines or only parts of it will be displayed.
	 *
	 * @param   string  $file_contents   File contents. Can be safely truncated to 8kiB as that's all WP itself scans.
	 * @param   array   $header_map      The list of headers to search for in the file.
	 * @return  array
	 */
	public static function get_file_headers( $file_contents, $header_map ) {
		$headers = array();

		//Support systems that use CR as a line ending.
		$file_contents = str_replace( "\r", "\n", $file_contents );

		foreach ( $header_map as $field => $prettyName ) {
			$found = preg_match( '/^[ \t\/*#@]*' . preg_quote( $prettyName, '/' ) . ':(.*)$/mi', $file_contents, $matches );
			if ( ( $found > 0) && ! empty( $matches[1] ) ) {
				//Strip comment markers and closing PHP tags.
				$value = trim(preg_replace( "/\s*(?:\*\/|\?>).*/", '', $matches[1] ) );
				$headers[ $field ] = $value;
			} else {
				$headers[ $field ] = '';
			}
		}

		return $headers;
	}

	/**
	 * Extract plugin metadata from a plugin's ZIP file and transform it into a structure
	 * compatible with the custom update checker.
	 *
	 * Deprecated. Included for backwards-compatibility.
	 *
	 * This is an utility function that scans the input file (assumed to be a ZIP archive )
	 * to find and parse the plugin's main PHP file and readme.txt file. Plugin metadata from 
	 * both files is assembled into an associative array. The structure if this array is 
	 * compatible with the format of the metadata file used by the custom plugin update checker 
	 * library available at the below URL.
	 * 
	 * @see http://w-shadow.com/blog/2010/09/02/automatic-updates-for-any-plugin/
	 * @see https://spreadsheets.google.com/pub?key=0AqP80E74YcUWdEdETXZLcXhjd2w0cHMwX2U1eDlWTHc&authkey=CK7h9toK&hl=en&single=true&gid=0&output=html
	 * 
	 * Requires the ZIP extension for PHP.
	 * @see http://php.net/manual/en/book.zip.php
	 * 
	 * @param   string|array  $package_info  Either path to a ZIP file containing a WP plugin, or the return value of analysePluginPackage().
	 * @return  array                        Associative array  
	 */
	public static function get_plugin_package_meta( $package_info ) {
		if ( is_string( $package_info ) && file_exists( $package_info ) ) {
			$package_info = self::parse_package( $package_info, true );
		}

		$meta = array();

		if ( isset( $package_info['header'] ) && ! empty( $package_info['header'] ) ) {
			$mapping = array(
				'Name'      => 'name',
				'Version'   => 'version',
				'PluginURI' => 'homepage',
				'Author'    => 'author',
				'AuthorURI' => 'author_homepage',
			);
			foreach( $mapping as $header_field => $meta_field ) {
				if ( array_key_exists( $header_field, $package_info['header'] ) && ! empty( $package_info['header'][ $header_field ] ) ) {
					$meta[ $meta_field ] = $package_info['header'][ $header_field ];
				} 
			}
		}

		if ( ! empty( $package_info['readme'] ) ) {
			$mapping = array( 'requires', 'tested');
			foreach( $mapping as $readme_field ) {
				if ( ! empty( $package_info['readme'][ $readme_field ] ) ) {
					$meta[ $readme_field ] = $package_info['readme'][ $readme_field ];
				} 
			}
			if ( ! empty( $package_info['readme']['sections'] ) && is_array( $package_info['readme']['sections'] ) ) {
				foreach( $package_info['readme']['sections'] as $section_name => $section_content ) {
					$section_name = str_replace( ' ', '_', strtolower( $section_name ) );
					$meta['sections'][ $section_name] = $section_content;
				}
			}

			//Check if we have an upgrade notice for this version
			if ( isset( $meta['sections']['upgrade_notice'] ) && isset( $meta['version'] ) ) {
				$regex = "@<h4>\s*" . preg_quote( $meta['version'] ) . "\s*</h4>[^<>]*?<p>(.+?)</p>@i";
				if ( preg_match( $regex, $meta['sections']['upgrade_notice'], $matches ) ) {
					$meta['upgrade_notice'] = trim( strip_tags( $matches[1] ) );
				}
			}
		}

		if ( ! empty( $package_info['plugin_file'] ) ) {
			$meta['slug'] = strtolower( basename( dirname( $package_info['plugin_file'] ) ) );
		}

		return $meta;
	}
}
endif;