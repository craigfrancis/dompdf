<?php

	require_once('./dompdf_pages.php');

	$dompdf = new dompdf_pages();

	$dompdf->set_paper(array(0, 0, 300, 200));
	$dompdf->load_html_file('example_file1.html');
	$dompdf->render();

	$dompdf->set_paper(array(0, 0, 150, 200));
	$dompdf->load_html_file('example_file2.html');
	$dompdf->render();

	$dompdf->stream("file.pdf", array('Attachment' => false));

?>