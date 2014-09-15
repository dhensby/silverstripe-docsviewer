<?php

/**
 * A class which builds a manifest of all documentation present in a project.
 *
 * The manifest is required to map the provided documentation URL rules to a 
 * file path on the server. The stored cache looks similar to the following:
 *
 * <code>
 * array(
 *     'en/someniceurl/' => array(
 *       'filepath' => '/path/to/docs/en/SomeniceFile.md',
 *		 'title' => 'Some nice URL',
 *		 'summary' => 'Summary Text',
 *		 'basename' => 'SomeniceFile.md',
 *		 'type' => 'DocumentationPage'
 *     )
 *   )
 * </code>
 *
 * URL format is in the following structures:
 *
 *  {lang}/{path}
 *	{lang}/{module}/{path}
 *  {lang}/{module}/{version}/{/path}
 *
 * @package framework
 * @subpackage manifest
 */
class DocumentationManifest {

	/**
	 * @config
	 *
	 * @var boolean $automatic_registration
	 */
	private static $automatic_registration = true;

	/**
	 * @config
	 *
	 * @var array $registered_entities
	 */
	private static $register_entities = array();

	protected $cache;
	protected $cacheKey;

	protected $inited;
	protected $forceRegen;

	/**
	 * @var array $pages
	 */
	protected $pages = array();

	/**
	 * @var DocumentationEntity
	 */
	private $entity;

	/**
	 * @var array
	 */
	private $registeredEntities = array();

	/**
	 * Constructs a new template manifest. The manifest is not actually built
	 * or loaded from cache until needed.
	 *
	 * @param bool $includeTests Include tests in the manifest.
	 * @param bool $forceRegen Force the manifest to be regenerated.
	 */
	public function __construct($forceRegen = false) {
		$this->cacheKey   = 'manifest';
		$this->forceRegen = $forceRegen;

		$this->cache = SS_Cache::factory('DocumentationManifest', 'Core', array(
			'automatic_serialization' => true,
			'lifetime' => null
		));

		$this->setupEntities();
	}

	/**
	 * Sets up the top level entities.
	 *
	 * Either manually registered through the YAML syntax or automatically 
	 * loaded through investigating the file system for `docs` folder.
	 */
	public function setupEntities() {
		if(Config::inst()->get('DocumentationManifest', 'automatic_registration')) {
			$this->populateEntitiesFromInstall();
		}

		$registered = Config::inst()->get('DocumentationManifest', 'register_entities');

		foreach($registered as $details) {
			// validate the details provided through the YAML configuration
			$required = array('Path', 'Title');

			foreach($required as $require) {
				if(!isset($details[$require])) {
					throw new Exception("$require is a required key in DocumentationManifest.register_entities");
				}
			}

			// if path is not an absolute value then assume it is relative from
			// the BASE_PATH.
			$path = $this->getRealPath($details['Path']);

			$key = (isset($details['Key'])) ? $details['Key'] : $details['Title'];
	
			if(!is_dir($path)) {
				throw new Exception($path . ' is not a valid documentation directory');
			}

			$version = (isset($details['Version'])) ? $details['Version'] : '';

			$langs = scandir($path);
			
			if($langs) {
				$possible = i18n::get_common_languages(true);
	
				foreach($langs as $k => $lang) {
					if(isset($possible[$lang])) {
						$entity = Injector::inst()->create(
							'DocumentationEntity', $key
						);

						$entity->setPath(Controller::join_links($path, $lang, '/'));
						$entity->setTitle($details['Title']);
						$entity->setLanguage($lang);
						$entity->setVersion($version);

						if(isset($details['Stable'])) {
							$entity->setIsStable($details['Stable']);
						}

						if(isset($details['DefaultEntity'])) {
							$entity->setIsDefaultEntity($details['DefaultEntity']);
						}

						$this->registeredEntities[] = $entity;
					}
				}
			}
		}
	}

	public function getRealPath($path) {
		if(substr($path, 0, 1) != '/') {
			$path = realpath(Controller::join_links(BASE_PATH, $path));
		}

		return $path;
	}

	/**
	 * @return array
	 */
	public function getEntities() {
		return $this->registeredEntities;
	}

	/**
	 * Scans the current installation and picks up all the SilverStripe modules
	 * that contain a `docs` folder.
	 *
	 * @return void
	 */
	public function populateEntitiesFromInstall() {
		$entities = array();

		foreach(scandir(BASE_PATH) as $key => $entity) {
			if($key == "themes") {
				continue;
			}

			$dir = is_dir(Controller::join_links(BASE_PATH, $entity));
			
			if($dir) {
				// check to see if it has docs
				$docs = Controller::join_links($dir, 'docs');

				if(is_dir($docs)) {
					$entities[] = array(
						'BasePath' => $entity,
						'Folder' => $key,
						'Version' => 'master',
						'Stable' => true
					);
				}
			}
		}

		Config::inst()->update(
			'DocumentationManifest', 'registered_entities', $entities
		);
	}

	/**
	 *
	 */
	protected function init() {
		if (!$this->forceRegen && $data = $this->cache->load($this->cacheKey)) {
			$this->pages = $data;
			$this->inited    = true;
		} else {
			$this->regenerate();
		}
	}


	/**
	 * Returns a map of all documentation pages.
	 *
	 * @return array
	 */
	public function getPages() {
		if (!$this->inited) {
			$this->init();
		}

		return $this->pages;
	}

	/**
	 * Returns a particular page for the requested URL. 
	 *
	 * @return DocumentationPage
	 */
	public function getPage($url) {
		$pages = $this->getPages();
		$url = $this->normalizeUrl($url);

		if(!isset($pages[$url])) {
			return null;
		}


		$record = $pages[$url];

		foreach($this->getEntities() as $entity) {
			if(strpos($record['filepath'], $entity->getPath()) !== false) {
				$page =  Injector::inst()->create(
					$record['type'], 
					$entity,
					$record['basename'],
					$record['filepath']
				);

				return $page;
			}
		}
	}

	/**
	 * Regenerates the manifest by scanning the base path.
	 *
	 * @param bool $cache
	 */
	public function regenerate($cache = true) {
		$finder = new DocumentationManifestFileFinder();
		$finder->setOptions(array(
			'dir_callback' => array($this, 'handleFolder'),
			'file_callback'  => array($this, 'handleFile')
		));

		foreach($this->getEntities() as $entity) {
			$this->entity = $entity;
		
			$this->handleFolder('', $this->entity->getPath(), 0);
			$finder->find($this->entity->getPath());
		}
		
		if ($cache) {
			$this->cache->save($this->pages, $this->cacheKey);
		}

		$this->inited = true;
	}

	/**
	 *
	 */
	public function handleFolder($basename, $path, $depth) {
		$folder = Injector::inst()->create(
			'DocumentationFolder', $this->entity, $basename, $path
		);

		$link = ltrim(str_replace(
			Config::inst()->get('DocumentationViewer', 'link_base'), 
			'', 
			$folder->Link()
		), '/');

		$this->pages[$link] = array(
			'title' => $folder->getTitle(),
			'basename' => $basename,
			'filepath' => $path,
			'type' => 'DocumentationFolder'
		);
	}

	/**
	 * Individual files can optionally provide a nice title and a better URL 
	 * through the use of markdown meta data. This creates a new 
	 * {@link DocumentationPage} instance for the file. 
	 *
	 * If the markdown does not specify the title in the meta data it falls back
	 * to using the file name.
	 *
	 * @param string $basename
	 * @param string $path
	 * @param int $depth
	 */
	public function handleFile($basename, $path, $depth) {
		$page = Injector::inst()->create(
			'DocumentationPage', 
			$this->entity, $basename, $path
		);

		// populate any meta data
		$page->getMarkdown();

		$link = ltrim(str_replace(
			Config::inst()->get('DocumentationViewer', 'link_base'), 
			'', 
			$page->Link()
		), '/');
		
		$this->pages[$link] = array(
			'title' => $page->getTitle(),
			'filepath' => $path,
			'basename' => $basename,
			'type' => 'DocumentationPage',
			'summary' => $page->getSummary()
		);
	}

	/**
	 * Generate an {@link ArrayList} of the pages to the given page.
	 *
	 * @param DocumentationPage
	 * @param DocumentationEntityLanguage
	 *
	 * @return ArrayList
	 */
	public function generateBreadcrumbs($record, $base) {
		$output = new ArrayList();

		$parts = explode('/', trim($record->getRelativeLink(), '/'));
		
		// the first part of the URL should be the language, so shift that off
		// so we just have the core pages.
		array_shift($parts);

		// Add the base link.
		$output->push(new ArrayData(array(
			'Link' => $base->Link(),
			'Title' => $base->Title
		)));

		$progress = $base->Link();

		foreach($parts as $part) {
			if($part) {
				$progress = Controller::join_links($progress, $part, '/');

				$output->push(new ArrayData(array(
					'Link' => $progress,
					'Title' => DocumentationHelper::clean_page_name($part)
				)));
			}
		}

		return $output;
	}

	/**
	 * Determine the next page from the given page.
	 *
	 * Relies on the fact when the manifest was built, it was generated in 
	 * order.
	 *
	 * @param string
	 *
	 * @return ArrayData
	 */
	public function getNextPage($filepath) {
		$grabNext = false;

		foreach($this->getPages() as $url => $page) {
			if($grabNext) {
				return new ArrayData(array(
					'Link' => $url,
					'Title' => $page['title']
				));
			}

			if($filepath == $page['filepath']) {
				$grabNext = true;
			}
		}

		return null;
	}

	/**
	 * Determine the previous page from the given page.
	 *
	 * Relies on the fact when the manifest was built, it was generated in 
	 * order.
	 *
	 * @param string
	 *
	 * @return ArrayData
	 */
	public function getPreviousPage($filepath) {
		$previousUrl = $previousPage = null;

		foreach($this->getPages() as $url => $page) {
			if($filepath == $page['filepath']) {
				if($previousUrl) {
					return new ArrayData(array(
						'Link' => $previousUrl,
						'Title' => $previousPage['title']
					));
				}
			}

			$previousUrl = $url;
			$previousPage = $page;
		}

		return null;
	}

	/**
	 * @param string
	 *
	 * @return string
	 */
	public function normalizeUrl($url) {
		return trim($url, '/') .'/';
	}

	/**
	 * Return the children of the provided record path.
	 *
	 * Looks for any pages in the manifest which have one more slash attached.
	 *
	 * @param string $path
	 *
	 * @return ArrayList
	 */
	public function getChildrenFor($path, $recursive = true) {
		$output = new ArrayList();
		$base = Config::inst()->get('DocumentationViewer', 'link_base');
		$path = $this->normalizeUrl($path);
		$depth = substr_count($path, '/');

		foreach($this->getPages() as $url => $page) {
			$pagePath = $this->normalizeUrl($page['filepath']);

			// check to see if this page is under the given path
			if(strpos($pagePath, $path) === false) {
				continue;
			}

			// if the page is the index page then hide it from the menu 
			if(strpos(strtolower($pagePath), '/index.md/')) {
				continue;
			}

			// only pull it up if it's one more level depth
			if(substr_count($pagePath, DIRECTORY_SEPARATOR) == ($depth + 1)) {
				// found a child
				$mode = ($pagePath == $path) ? 'current' : 'link';
				$children = new ArrayList();

				if($mode == 'current' && $recursive) {
				//	$children = $this->getChildrenFor($url, false);
				}

				$output->push(new ArrayData(array(
					'Link' => Controller::join_links($base, $url, '/'),
					'Title' => $page['title'],
					'LinkingMode' => $mode,
					'Children' => $children
				)));
			}
		}

		return $output;
	}

	/**
	 * @param DocumentationEntity
	 *
	 * @return ArrayList
	 */	
	public function getAllVersions(DocumentationEntity $entity) {
		$all = new ArrayList();

		foreach($this->getEntities() as $check) {
			if($check->getKey() == $entity->getKey()) {
				if($check->getLanguage() == $entity->getLanguage()) {
					$all->push($check);
				}
			}
		}

		return $all;
	}

	/**
	 * @param DocumentationEntity
	 *
	 * @return DocumentationEntity
	 */	
	public function getStableVersion(DocumentationEntity $entity) {
		foreach($this->getEntities() as $check) {
			if($check->getKey() == $entity->getKey()) {
				if($check->getLanguage() == $entity->getLanguage()) {
					if($check->getIsStable()) {
						return $check;
					}
				}
			}
		}

		return $entity;
	}

	/**
	 * @param DocumentationEntity
	 *
	 * @return ArrayList
	 */	
	public function getVersions($entity) {
		if(!$entity) {
			return null;
		}

		$output = new ArrayList();

		foreach($this->getEntities() as $check) {
			if($check->getKey() == $entity->getKey()) {
				if($check->getLanguage() == $entity->getLanguage()) {
					$same = ($check->getVersion() == $entity->getVersion());

					$output->push(new ArrayList(array(
						'Title' => $entity->getTitle(),
						'Link' => $entity->getLink(),
						'LinkingMode' => ($same) ? 'current' : 'link'
					)));

				}
			}
		}

		return $output;
	}
}
