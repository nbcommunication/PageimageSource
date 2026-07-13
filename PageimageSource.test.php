<?php namespace ProcessWire;

/**
 * Tests for PageimageSource
 *
 * Run via: php index.php test PageimageSource
 * (or `php index.php test all`, or the WireTests admin page)
 *
 */
class WireTest_PageimageSource extends WireTest {

	/**
	 * @var string
	 */
	protected $fieldName;

	/**
	 * Was $input->post['defaultSets'] set before this test ran?
	 *
	 * @var bool
	 */
	protected $postDefaultSetsWasSet = false;

	/**
	 * Original $input->post['defaultSets'] value, restored in finish()
	 *
	 * @var mixed
	 */
	protected $originalPostDefaultSets = null;

	/**
	 * Only run if the sample fixture image (bundled with core FieldtypeImage tests) is available
	 *
	 * @return bool
	 *
	 */
	public function allow() {
		return is_file($this->getSampleImagePath());
	}

	/**
	 * Path to a small sample image reused from core's own FieldtypeImage tests
	 *
	 * @return string
	 *
	 */
	protected function getSampleImagePath() {
		return $this->wire()->config->paths->root . 'wire/modules/Fieldtype/FieldtypeImage/tests/images/test1.jpg';
	}

	public function init() {
		$this->fieldName = WireTests::fieldPrefix . 'pageimagesource_image';
		$this->ensureField();
	}

	/**
	 * Ensure a test image field (with a sample image already uploaded) exists on the test page
	 *
	 */
	protected function ensureField() {
		$fields = $this->wire()->fields;
		$modules = $this->wire()->modules;
		$page = $this->getTestPage();
		$name = $this->fieldName;
		$field = $fields->get($name);

		if(!$field) {
			$field = new ImageField();
			$field->name = $name;
			$field->type = $modules->get('FieldtypeImage');
			$field->label = 'Test Image (PageimageSource)';
			$field->extensions = 'jpg jpeg png gif';
			$field->maxFiles = 0;
			$field->outputFormat = FieldtypeFile::outputFormatArray;
			$field->save();
			$this->li("Created field: $field->name");
		}

		$fieldgroup = $page->template->fieldgroup;
		if(!$fieldgroup->hasField($field)) {
			$fieldgroup->add($field);
			$fieldgroup->save();
			$this->li("Added field to fieldgroup: $fieldgroup->name");
		}

		$page->of(false);
		if(!$page->get($name)->count()) {
			$page->get($name)->add($this->getSampleImagePath());
			$page->save($name);
			$this->li('Added sample image to test page');
		}
	}

	public function execute() {

		$modules = $this->wire()->modules;
		$input = $this->wire()->input;
		$mod = $modules->get('PageimageSource');
		$page = $this->getTestPage();
		$page->of(false);
		$image = $page->get($this->fieldName)->first();

		if(!$image) $this->fail('Sample image is not available on test page');

		// --- getSets() / getSrcset() rule parsing ---

		$sets = $mod->getSets("320\n640\n1024");
		$this->check('getSets() parses plain width rules (count)', 3, count($sets));
		$this->check('getSets() key "320w" present', true, isset($sets['320w']));
		$this->check('getSets() dimensions for "320w"', [ 320, 0 ], $sets['320w'] ?? null);

		$sets = $mod->getSets("300x200\n640");
		$this->check('getSets() parses "WxH" rule dimensions', [ 300, 200 ], $sets['300w'] ?? null);

		$sets = $mod->getSets("640 640w\n300x200 2x");
		$this->check('getSets() supports explicit rule label "640w"', [ 640, 0 ], $sets['640w'] ?? null);
		$this->check('getSets() supports explicit rule label "2x"', [ 300, 200 ], $sets['2x'] ?? null);

		$sets = $mod->getSets("320\nbogus\n640\ntoo many parts here\n0x0");
		$this->check('getSets() silently filters invalid rules (count)', 2, count($sets));

		// --- srcset() / httpSrcset() generation ---

		$srcset = $image->srcset();
		$this->check('srcset() returns non-empty string', true, is_string($srcset) && $srcset !== '');
		$this->check('srcset() references sample image basename', true, strpos($srcset, 'test1') !== false);

		$httpSrcset = $image->httpSrcset();
		$this->check('httpSrcset() returns an absolute URL', true, strpos($httpSrcset, 'http') === 0);

		$multi = $image->srcset('100,200,500', [ 'allSets' => true ]);
		$this->check('srcset() with allSets:true produces multiple entries', 3, count(explode(',', $multi)));

		// --- render() ---

		$rendered = $image->render();
		$this->check('render() wraps output in <picture> (webp + picture enabled)', true, strpos($rendered, '<picture>') === 0);
		$this->check('render() includes a webp <source>', true, strpos($rendered, 'type="image/webp"') !== false);
		$this->check('render() includes a fallback <source>', true, strpos($rendered, 'type="image/jpeg"') !== false);
		$this->check('render() includes loading="lazy"', true, preg_match('/loading=[\'"]lazy[\'"]/', $rendered) === 1);

		// --- Regression: render(false) must not permanently mutate module state ---
		// Prior to the PageimageSource bugfix migration, $image->render(false) set
		// useLazy/usePicture/webp = false on the *module instance*, silently breaking
		// every subsequent render() call for the rest of the request. This is a
		// non-aborting check so the rest of this test still runs either way.

		$before = [ $mod->useLazy, $mod->usePicture, $mod->webp ];
		$image->render(false);
		$after = [ $mod->useLazy, $mod->usePicture, $mod->webp ];

		if($before === $after) {
			$this->ok('render(false) does not mutate module useLazy/usePicture/webp state');
		} else {
			$this->tests->fail(
				'Known bug: render(false) mutated module state from ' .
				json_encode($before) . ' to ' . json_encode($after) .
				' - apply the PageimageSource bugfix migration'
			);
			// Restore state so later tests in this same request aren't affected.
			$mod->useLazy = $before[0];
			$mod->usePicture = $before[1];
			$mod->webp = $before[2];
		}

		// --- Regression: getSrcset() must not crash when $input->post['defaultSets']
		// is a string (as it always is when posted from a textarea) and invalid rules
		// are present. Prior to the fix this threw a fatal TypeError on PHP 8+ from
		// count($input->post['defaultSets']). Also a non-aborting check.

		$this->postDefaultSetsWasSet = isset($input->post['defaultSets']);
		$this->originalPostDefaultSets = $this->postDefaultSetsWasSet ? $input->post['defaultSets'] : null;

		$input->post['defaultSets'] = "640\nbogus\n";
		try {
			$mod->getSets("640\nbogus\n");
			$this->ok("getSets() does not throw when \$input->post['defaultSets'] is a string and invalid rules are present");
		} catch(\TypeError $e) {
			$this->tests->fail(
				"Known bug: TypeError in count(\$input->post['defaultSets']) - " . $e->getMessage() .
				' - apply the PageimageSource bugfix migration'
			);
		}
	}

	public function finish() {
		$input = $this->wire()->input;
		if($this->postDefaultSetsWasSet) {
			$input->post['defaultSets'] = $this->originalPostDefaultSets;
		} else {
			unset($input->post['defaultSets']);
		}
	}
}
