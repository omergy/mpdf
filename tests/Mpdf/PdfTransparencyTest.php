<?php

namespace Mpdf;

class PdfTransparencyTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{
	/**
	 * @dataProvider transparencyProfiles
	 */
	public function testPngTransparencyAndOpacityRespectOutputProfile($config, $allowed, $palette, $colorSpace)
	{
		$mpdf = new Mpdf($config + ['restrictColorSpace' => $colorSpace]);
		$mpdf->compress = false;
		$image = $palette ? imagecreate(3, 1) : imagecreatetruecolor(3, 1);
		imagealphablending($image, false);
		imagesavealpha($image, true);
		foreach ([127, 64, 0] as $x => $alpha) {
			imagesetpixel($image, $x, 0, imagecolorallocatealpha($image, 0, 0, 0, $alpha));
		}
		ob_start();
		imagepng($image);
		$mpdf->imageVars['transparent'] = ob_get_clean();
		imagedestroy($image);

		$mpdf->WriteHTML('<img src="var:transparent" width="30">');
		$state = $mpdf->SetAlpha(0.5, 'Normal', true);
		$output = $mpdf->Output('', 'S');

		$this->assertSame($allowed, strpos($output, '/SMask ') !== false);
		$this->assertSame($allowed, strpos($output, '/Group << /Type /Group /S /Transparency /CS /Device') !== false);
		preg_match('/GS(\d+)/', $state, $match);
		$this->assertEquals($allowed ? 0.5 : 1, $mpdf->extgstates[$match[1]]['parms']['ca']);
	}

	public function transparencyProfiles()
	{
		$profiles = [
			'pdf' => [[], true],
			'pdfa1' => [['PDFA' => true, 'PDFAauto' => true, 'PDFAversion' => '1-B'], false],
			'pdfa2' => [['PDFA' => true, 'PDFAauto' => true, 'PDFAversion' => '2-B'], true],
			'pdfa3' => [['PDFA' => true, 'PDFAauto' => true, 'PDFAversion' => '3-B'], true],
			'pdfx' => [['PDFX' => true, 'PDFXauto' => true], false],
		];
		foreach ($profiles as $name => $profile) {
			foreach ([false, true] as $palette) {
				foreach ([0, 1, 3] as $colorSpace) {
					yield $name . '-palette-' . (int) $palette . '-colorspace-' . $colorSpace => [$profile[0], $profile[1], $palette, $colorSpace];
				}
			}
		}
	}

	public function testSvgGradientAndSoftMaskHaveLocalResources()
	{
		$mpdf = new Mpdf(['PDFA' => true, 'PDFAauto' => true, 'PDFAversion' => '3-B']);
		$mpdf->compress = false;
		$mpdf->WriteHTML('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="20">'
			. '<defs><linearGradient id="fade"><stop offset="0" stop-color="red"/>'
			. '<stop offset="1" stop-color="white" stop-opacity="0"/></linearGradient></defs>'
			. '<rect width="100" height="20" fill="url(#fade)"/></svg>');
		$output = $mpdf->Output('', 'S');

		preg_match_all('/\d+ 0 obj\s*(.*?)endobj/s', $output, $objects);
		$found = false;
		foreach ($objects[1] as $object) {
			if (strpos($object, '/Subtype /Form') === false || strpos($object, '/Sh1 sh') === false) {
				continue;
			}
			$found = true;
			$resources = strstr($object, 'stream', true);
			$this->assertMatchesRegularExpression('#/Shading\s*<<.*?/Sh1\s+\d+ 0 R#s', $resources);
			$this->assertMatchesRegularExpression('#/ExtGState\s*<<.*?/TGS1\s+\d+ 0 R#s', $resources);
		}
		$this->assertTrue($found, 'Expected an SVG form that uses the gradient');
	}
}
