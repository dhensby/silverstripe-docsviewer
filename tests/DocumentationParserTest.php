<?php

/**
 * @package docsviewer
 * @subpackage tests
 */
class DocumentationParserTest extends SapphireTest {
	
	protected $entity, $entityAlt, $page, $subPage, $subSubPage, $filePage, $metaDataPage, $indexPage;

	public function tearDown() {
		parent::tearDown();
		
		Config::unnest();
	}

	public function setUp() {
		parent::setUp();

		Config::nest();

		// explicitly use dev/docs. Custom paths should be tested separately 
		Config::inst()->update(
			'DocumentationViewer', 'link_base', 'dev/docs/'
		);

		$this->entity = new DocumentationEntity('DocumentationParserTest');
		$this->entity->setPath(DOCSVIEWER_PATH . '/tests/docs/en/');
		$this->entity->setVersion('2.4');
		$this->entity->setLanguage('en');


		$this->entityAlt = new DocumentationEntity('DocumentationParserParserTest');
		$this->entityAlt->setPath(DOCSVIEWER_PATH . '/tests/docs-parser/en/');
		$this->entityAlt->setVersion('2.4');
		$this->entityAlt->setLanguage('en');

		$this->page = new DocumentationPage(
			$this->entity, 
			'test.md', 
			DOCSVIEWER_PATH . '/tests/docs/en/test.md'
		);

		$this->subPage = new DocumentationPage(
			$this->entity, 
			'subpage.md', 
			DOCSVIEWER_PATH. '/tests/docs/en/subfolder/subpage.md'
		);	
			
		$this->subSubPage = new DocumentationPage(
			$this->entity,
			'subsubpage.md',
			DOCSVIEWER_PATH. '/tests/docs/en/subfolder/subsubfolder/subsubpage.md'	
		);

		$this->filePage =  new DocumentationPage(
			$this->entityAlt,
			'file-download.md',
			DOCSVIEWER_PATH . '/tests/docs-parser/en/file-download.md'
		);

		$this->metaDataPage = new DocumentationPage(
			$this->entityAlt,
			'MetaDataTest.md',
			DOCSVIEWER_PATH . '/tests/docs-parser/en/MetaDataTest.md'
		);

		$this->indexPage = new DocumentationPage(
			$this->entity,
			'index.md',
			DOCSVIEWER_PATH. '/tests/docs/en/index.md'
		);

		$manifest = new DocumentationManifest(true);
	}

	public function testRelativeLinks() {
		// index.md
		$result = DocumentationParser::rewrite_relative_links(
			$this->indexPage->getMarkdown(), 
			$this->indexPage
		);
		
		$this->assertContains(
			'[link: subfolder index](dev/docs/en/documentationparsertest/2.4/subfolder/)',
			$result
		);

		// test.md

		$result = DocumentationParser::rewrite_relative_links(
			$this->page->getMarkdown(), 
			$this->page
		);
		
		$this->assertContains(
			'[link: subfolder index](dev/docs/en/documentationparsertest/2.4/subfolder/)',
			$result
		);
		$this->assertContains(
			'[link: subfolder page](dev/docs/en/documentationparsertest/2.4/subfolder/subpage/)',
			$result
		);
		$this->assertContains(
			'[link: http](http://silverstripe.org)',
			$result
		);
		$this->assertContains(
			'[link: api](api:DataObject)',
			$result
		);

		
		$result = DocumentationParser::rewrite_relative_links(
			$this->subPage->getMarkdown(), 
			$this->subPage
		);

		# @todo this should redirect to /subpage/
		$this->assertContains(
			'[link: relative](dev/docs/en/documentationparsertest/2.4/subfolder/subpage.md/)',
			$result
		);
		
		$this->assertContains(
			'[link: absolute index](dev/docs/en/documentationparsertest/2.4/)',
			$result
		);

		# @todo this should redirect to /
		$this->assertContains(
			'[link: absolute index with name](dev/docs/en/documentationparsertest/2.4/index/)',
			$result
		);

		$this->assertContains(
			'[link: relative index](dev/docs/en/documentationparsertest/2.4/)',
			$result
		);
		
		$this->assertContains(
			'[link: relative parent page](dev/docs/en/documentationparsertest/2.4/test/)',
			$result
		);
		
		$this->assertContains(
			'[link: absolute parent page](dev/docs/en/documentationparsertest/2.4/test/)',
			$result
		);
		
		$result = DocumentationParser::rewrite_relative_links(
			$this->subSubPage->getMarkdown(), 
			$this->subSubPage
		);
		
		$this->assertContains(
			'[link: absolute index](dev/docs/en/documentationparsertest/2.4/)',
			$result
		);

		$this->assertContains(
			'[link: relative index](dev/docs/en/documentationparsertest/2.4/subfolder/)',
			$result
		);

		$this->assertContains(
			'[link: relative parent page](dev/docs/en/documentationparsertest/2.4/subfolder/subpage/)',
			$result
		);

		$this->assertContains(
			'[link: relative grandparent page](dev/docs/en/documentationparsertest/2.4/test/)',
			$result
		);

		$this->assertContains(
			'[link: absolute page](dev/docs/en/documentationparsertest/2.4/test/)',
			$result
		);
	}

	public function testGenerateHtmlId() {
		$this->assertEquals('title-one', DocumentationParser::generate_html_id('title one'));
		$this->assertEquals('title-one', DocumentationParser::generate_html_id('Title one'));
		$this->assertEquals('title-and-one', DocumentationParser::generate_html_id('Title &amp; One'));
		$this->assertEquals('title-and-one', DocumentationParser::generate_html_id('Title & One'));
		$this->assertEquals('title-one', DocumentationParser::generate_html_id(' Title one '));
		$this->assertEquals('title-one', DocumentationParser::generate_html_id('Title--one'));
	}

	public function testRewriteCodeBlocks() {
		$result = DocumentationParser::rewrite_code_blocks(
			$this->page->getMarkdown()
		);

		$expected = <<<HTML
<pre class="brush: php">
code block
with multiple
lines
	and tab indent
	and escaped &lt; brackets</pre>

Normal text after code block
HTML;


		$this->assertContains($expected, $result, 'Custom code blocks with ::: prefix');		
		
		$expected = <<<HTML
<pre>
code block
without formatting prefix</pre>
HTML;
		$this->assertContains($expected, $result, 'Traditional markdown code blocks');

		$expected = <<<HTML
<pre class="brush: ">
Fenced code block
</pre>
HTML;
		$this->assertContains($expected, $result, 'Backtick code blocks');
		
		$expected = <<<HTML
<pre class="brush: php">
Fenced box with

new lines in

between

content
</pre>
HTML;
		$this->assertContains($expected, $result, 'Backtick with newlines');
	}
	
	public function testImageRewrites() {
		
		$result = DocumentationParser::rewrite_image_links(
			$this->subPage->getMarkdown(), 
			$this->subPage
		);

		$expected = Controller::join_links(
			Director::absoluteBaseURL(), DOCSVIEWER_DIR, '/tests/docs/en/subfolder/_images/image.png'
		);

		$this->assertContains(
			sprintf('[relative image link](%s)', $expected),
			$result
		);

		$this->assertContains(
			sprintf('[parent image link](%s)', Controller::join_links(
				Director::absoluteBaseURL(), DOCSVIEWER_DIR, '/tests/docs/en/_images/image.png'
			)),
			$result
		);
		
		$expected = Controller::join_links(
			Director::absoluteBaseURL(), DOCSVIEWER_DIR, '/tests/docs/en/_images/image.png'
		);

		$this->assertContains(
			sprintf('[absolute image link](%s)', $expected), 
			$result
		);
	}
	
	public function testApiLinks() {
		$result = DocumentationParser::rewrite_api_links(
			$this->page->getMarkdown(), 
			$this->page
		);

		$this->assertContains(
			'[link: api](http://api.silverstripe.org/search/lookup/?q=DataObject&version=2.4&module=documentationparsertest)',
			$result
		);
		$this->assertContains(
			'[DataObject::$has_one](http://api.silverstripe.org/search/lookup/?q=DataObject::$has_one&version=2.4&module=documentationparsertest)',
			$result
		);
	}
	
	public function testHeadlineAnchors() {
		$result = DocumentationParser::rewrite_heading_anchors(
			$this->page->getMarkdown(), 
			$this->page
		);
		
		/*
		# Heading one {#Heading-one}

		# Heading with custom anchor {#custom-anchor} {#Heading-with-custom-anchor-custom-anchor}

		## Heading two {#Heading-two}

		### Heading three {#Heading-three}

		## Heading duplicate {#Heading-duplicate}

		## Heading duplicate {#Heading-duplicate-2}

		## Heading duplicate {#Heading-duplicate-3}
		
		*/

		$this->assertContains('# Heading one {#heading-one}', $result);
		$this->assertContains('# Heading with custom anchor {#custom-anchor}', $result);
		$this->assertNotContains('# Heading with custom anchor {#custom-anchor} {#heading', $result);
		$this->assertContains('# Heading two {#heading-two}', $result);
		$this->assertContains('# Heading three {#heading-three}', $result);
		$this->assertContains('## Heading duplicate {#heading-duplicate}', $result);
		$this->assertContains('## Heading duplicate {#heading-duplicate-2}', $result);
		$this->assertContains('## Heading duplicate {#heading-duplicate-3}', $result);
		
	}
		


	public function testRetrieveMetaData() {
		DocumentationParser::retrieve_meta_data($this->metaDataPage);
		
		$this->assertEquals('Dr. Foo Bar.', $this->metaDataPage->author);
		$this->assertEquals("Foo Bar's Test page.", $this->metaDataPage->getTitle());
	}
	
	public function testRewritingRelativeLinksToFiles() {
		$parsed = DocumentationParser::parse($this->filePage);

		$this->assertContains(
			DOCSVIEWER_DIR .'/tests/docs-parser/en/_images/external_link.png',
			$parsed
		);
		
		$this->assertContains(
			DOCSVIEWER_DIR .'/tests/docs-parser/en/_images/test.tar.gz',
			$parsed
		);
	}
}