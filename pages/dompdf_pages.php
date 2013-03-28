<?php

/*--------------------------------------------------

The current CPDF library used by DOMPDF was setup
to only use a single 'pages' object, so the included
patch needs to be applied:

	patch ./lib/class.pdf.php < class.pdf.php.diff

Hack by ~yk~
  November 10th, 2011, 04.58am

Update by Craig Francis
  26th March 2013, 16:00

-------------------------------------------------- */

require_once(dirname(__FILE__).'/../dompdf_config.inc.php');

class dompdf_pages {

	private $dompdf = NULL;
	private $new_dompdf_flag = FALSE;
	private $pdfs = array();

	public function __construct() {
		$this->dompdf = new DOMPDF();
		$this->new_dompdf_flag = TRUE;
	}

	private function _combine() {

		//--------------------------------------------------
		// Base objects

			$this->output_objects = array(
				1 => array(
						't' => 'catalog',
						'info' => array(
								'pages' => 2
							),
					),
				2 => array(
					't' => 'pages',
					'info' => array(
							'pages' => NULL,
							'page_count' => NULL,
						),
					),
			);

			$this->output_root_id = 2;
			$this->output_object_id = 2; // We start with 2 objects
			$this->output_page_id = 0;
			$this->output_fonts = array();
			$this->output_font_info = array();
			$this->output_font_id = 0;
			$this->output_image_id = 0;
			$this->output_info_id = NULL;

		//--------------------------------------------------
		// Add PDFs

			foreach ($this->pdfs as $pdf_id => $pdf_ref) {

				$pdf_ref->render();

				$cpdf = $pdf_ref->get_canvas()->get_cpdf();

				// print_r($cpdf->fonts);
				// print_r($cpdf->objects);
				// exit();

				$this->current_objects = $cpdf->objects;
				$this->current_object_map = array();
				$this->current_fonts = $cpdf->fonts;
				$this->current_font_map = array();
				$this->current_image_map = array();

				foreach ($cpdf->objects as $k => $o) {

					if ($o['t'] == 'pages') { // skip catalog/outlines entries

						$page_ids[] = $this->_combine_object_add($k);

					} else if ($pdf_id == 0 && $o['t'] == 'info') {

						$this->output_info_id = $this->_combine_object_add($k);

					}

				}

				// if (count($this->current_objects) > 0) {
				// 	print_r($this->current_objects);
				// }

			}

			ksort($this->output_objects);

		//--------------------------------------------------
		// Replace initial pages array (typically object 3)

			if ($this->output_info_id === NULL) {
				exit('Could not find info object');
			}

			$this->output_objects[$this->output_root_id]['info']['pages'] = $page_ids;
			$this->output_objects[$this->output_root_id]['info']['page_count'] = $this->output_page_id;

		//--------------------------------------------------
		// Replace DOMPDF objects

			$this->pdfs = array();

			$cpdf = $this->dompdf->get_canvas()->get_cpdf();
			$cpdf->fonts = $this->output_fonts;
			$cpdf->objects = $this->output_objects;
			$cpdf->infoObject = $this->output_info_id;

			// print_r($this->output_fonts);
			// print_r($this->output_objects);
			// exit();

	}

	private function _combine_font_add($k, $old_num) {

		//--------------------------------------------------
		// Font info

			if (isset($this->current_object_map[$k])) {

				$new_id = $this->current_object_map[$k]; // Already added

				$font_name = $this->output_objects[$new_id]['info']['name'];
				$font_file = $this->output_objects[$new_id]['info']['fontFileName'];

			} else {

				$font_name = $this->current_objects[$k]['info']['name'];
				$font_file = $this->current_objects[$k]['info']['fontFileName'];

			}

		//--------------------------------------------------
		// Get font number, or add.

			if (isset($this->output_font_info[$font_name])) {

				$fontNum = $this->output_font_info[$font_name]['fontNum'];

			} else {

				$fontNum = (++$this->output_font_id);

				if (!isset($this->current_fonts[$font_file])) {
					exit('Missing font description for "' . $font_name . '" (' . $font_file . ')');
				} else {
					$this->output_fonts[$font_file] = $this->current_fonts[$font_file];
					$this->output_fonts[$font_file]['fontNum'] = $fontNum;
				}

				$new_id = $this->_combine_object_add($k);

				$this->output_font_info[$font_name] = array(
						'objNum' => $new_id,
						'fontNum' => $fontNum,
					);

				$this->output_objects[$new_id]['info']['fontNum'] = $fontNum;

			}

			$this->current_font_map[$old_num] = $fontNum;

		//--------------------------------------------------
		// Return

			return $this->output_font_info[$font_name];

	}

	private function _combine_font_update($match) {
		return $match[1] . $this->current_font_map[$match[2]] . $match[3];
	}

	private function _combine_image_update($match) {
		return $match[1] . $this->current_image_map[$match[2]] . $match[3];
	}

	private function _combine_object_add($k) {

		//--------------------------------------------------
		// If already added

			if (isset($this->current_object_map[$k])) {
				return $this->current_object_map[$k];
			}

		//--------------------------------------------------
		// Get object, and remove

			$o = $this->current_objects[$k];

			unset($this->current_objects[$k]);

		//--------------------------------------------------
		// Generate new id, and place in map (added)

			$new_id = (++$this->output_object_id);

			$this->current_object_map[$k] = $new_id;

		//--------------------------------------------------
		// Update object

			if ($o['t'] == 'catalog') {

				$o['info']['outlines'] = $this->_combine_object_add($o['info']['outlines']);
				$o['info']['pages'] = $this->_combine_object_add($o['info']['pages']);

			} else if ($o['t'] == 'pages') {

				$o['info']['parent'] = $this->output_root_id;
				$o['info']['procset'] = $this->_combine_object_add($o['info']['procset']);

				$fonts = array();
				foreach ($o['info']['fonts'] as $font) {
					$fonts[] = $this->_combine_font_add($font['objNum'], $font['fontNum']);
				}
				$o['info']['fonts'] = $fonts;

				if (isset($o['info']['xObjects'])) {
					$new_objects = array();
					foreach ($o['info']['xObjects'] as $object) {

						$label = (++$this->output_image_id);
						$this->current_image_map[$object['label']] = $label;

						$object['objNum'] = $this->_combine_object_add($object['objNum']);
						$object['label'] = $label;

						$new_objects[] = $object;

					}
					$o['info']['xObjects'] = $new_objects;
				}

				$new_page_ids = array();
				foreach ($o['info']['pages'] as $old_page_id) {
					$new_page_ids[] = $this->_combine_object_add($old_page_id);
				}
				$o['info']['pages'] = $new_page_ids;

			} else if ($o['t'] == 'page') {

				$new_content_ids = array();
				foreach ($o['info']['contents'] as $content_id) {
					$new_content_ids[] = $this->_combine_object_add($content_id);
				}

				$o['info']['parent'] = $this->_combine_object_add($o['info']['parent']);
				$o['info']['pageNum'] = (++$this->output_page_id);
				$o['info']['contents'] = $new_content_ids;

				if (isset($o['info']['annot'])) {
					$new_annotation_ids = array();
					foreach ($o['info']['annot'] as $annotation_id) {
						$new_annotation_ids[] = $this->_combine_object_add($annotation_id);
					}
					$o['info']['annot'] = $new_annotation_ids;
				}

			} else if ($o['t'] == 'annotation') {

				foreach (array('actionId') as $attr) {
					if (isset($o['info'][$attr])) $o['info'][$attr] = $this->_combine_object_add($o['info'][$attr]);
				}

			} else if ($o['t'] == 'font') {

				foreach (array('toUnicode', 'cidFont', 'FontDescriptor') as $attr) {
					if (isset($o['info'][$attr])) $o['info'][$attr] = $this->_combine_object_add($o['info'][$attr]);
				}

			} else if ($o['t'] == 'fontDescriptor') {

				foreach (array('FontFile2') as $attr) {
					if (isset($o['info'][$attr])) $o['info'][$attr] = $this->_combine_object_add($o['info'][$attr]);
				}

			} else if ($o['t'] == 'fontDescendentCID') {

				foreach (array('cidSystemInfo', 'cidToGidMap', 'FontDescriptor') as $attr) {
					if (isset($o['info'][$attr])) $o['info'][$attr] = $this->_combine_object_add($o['info'][$attr]);
				}

			} else if ($o['t'] == 'contents') {

				if (isset($o['onPage'])) {

					$o['onPage'] = $this->_combine_object_add($o['onPage']);

					if (isset($o['c'])) { // Update font numbers in text fields
						$o['c'] = preg_replace_callback('/^(BT .*? \/F)([0-9]+)( [0-9\.]+ Tf )/m', array($this, '_combine_font_update'), $o['c']);
						$o['c'] = preg_replace_callback('/(\nq\n[0-9\.]+ 0 0 [0-9\.]+ [0-9\.]+ [0-9\.]+ cm \/)(I[0-9]+)( Do)/', array($this, '_combine_image_update'), $o['c']);
					}

				}

			}

		//--------------------------------------------------
		// Record new object, and return

			$this->output_objects[$new_id] = $o;

			return $new_id;

	}

	public function add_info($label, $value) {
		return $this->dompdf->add_info($label, $value);
	}

	public function set_paper($size, $orientation = "portrait") {
		if (!$this->new_dompdf_flag) {
			$this->dompdf = new DOMPDF();
			$this->new_dompdf_flag = TRUE;
		}
		return $this->dompdf->set_paper($size, $orientation);
	}

	public function load_html($str, $encoding = null) {
		if (!$this->new_dompdf_flag) {
			$this->dompdf = new DOMPDF();
			$this->new_dompdf_flag = TRUE;
		}
		return $this->dompdf->load_html($str, $encoding);
	}

	public function load_html_file($file) {
		if (!$this->new_dompdf_flag) {
			$this->dompdf = new DOMPDF();
			$this->new_dompdf_flag = TRUE;
		}
		return $this->dompdf->load_html_file($file);
	}

	public function render() {
		$this->pdfs[] = $this->dompdf;
		$this->new_dompdf_flag = FALSE;
	}

	public function output($options = null) {
		$this->_combine();
		return $this->dompdf->output($options);
	}

	public function output_html() {
		$this->_combine();
		return $this->dompdf->output_html();
	}

	public function stream($filename, $options = null) {
		$this->_combine();
		return $this->dompdf->stream($filename, $options);
	}

}

?>