<?php

namespace ZsgsDesign\PDFConverter;

use ZsgsDesign\PDFConverter\LatextException;
use ZsgsDesign\PDFConverter\LatexPdfWasGenerated;
use ZsgsDesign\PDFConverter\LatexPdfFailed;
use ZsgsDesign\PDFConverter\ViewNotFoundException;
use Symfony\Component\Process\Process;
use Illuminate\Support\Str;

class Latex
{
    /**
     * Stub view file path
     * @var string
     */
    private $stubPath;

    /**
     * Data to pass to the stub
     *
     * @var array
     */
    private $data = [];

    /**
     * Rendered tex file
     *
     * @var string
     */
    private $renderedTex;

    /**
     * If it's a raw tex or a view file
     * @var boolean
     */
    private $isRaw = false;

    /**
     * Metadata of the generated pdf
     * @var mixed
     */
    private $metadata = [];

    /**
     * Path of pdflatex
     *
     * @var string
     */
    private $binPath;

    /**
     * File Name inside Zip
     *
     * @var string
     */
    private $nameInsideZip;

    /**
     * Run in non-stop interaction mode
     * @var boolean
     */
    private $runNonStop = false;

    /**
     * Run until the .aux file stops changing
     * @var boolean
     */
    private $runUntilAuxSettles = false;

    /**
     * Construct the instance
     *
     * @param string $stubPath
     * @param mixed $metadata
     */
    public function __construct($latexBinary = 'pdflatex')
    {
        $this->binPath = $latexBinary;
    }

    /**
     * Set the path of pdflatex
     *
     * @param  string $binPath
     *
     * @return void
     */
    public function binPath($binPath)
    {
        if (is_string($binPath)) {
            $this->binPath = $binPath;
        }

        return $this;
    }

    /**
     * Set the view to render
     *
     * @param  string $tag
     *
     * @return this
     */
    public function view($tag, $data = [])
    {
        if ($tag instanceof RawTex) {
            $this->isRaw = true;
            $this->renderedTex = $tag->getTex();
        } else {
            $this->stubPath = $tag;
        }
        $this->data = $data;

        return $this;
    }

    /**
     * Set name inside zip file
     *
     * @param  string $nameInsideZip
     *
     * @return void
     */
    public function setName($nameInsideZip)
    {
        if (is_string($nameInsideZip)) {
            $this->nameInsideZip = basename($nameInsideZip);
        }

        return $this;
    }

    /**
     * Get name inside zip file
     *
     * @return string
     */
    public function getName()
    {
        return $this->nameInsideZip;
    }

    /**
     * Set whether we run latex in non-stop interaction mode
     *
     * @return $this
     */
    public function nonStop()
    {
        $this->runNonStop = true;

        return $this;
    }

    /**
     * Set whether we run latex until the aux file settles down
     *
     * @return $this
     */
    public function untilAuxSettles()
    {
        $this->runUntilAuxSettles = true;

        return $this;
    }

    /**
     * Dry run
     *
     * @return Illuminate\Http\Response
     */
    public function dryRun()
    {
        $this->isRaw = true;

        $this->renderedTex = \File::get(dirname(__FILE__).'/dryrun.tex');
        
        return $this->download('dryrun.pdf');
    }

    /**
     * Render the stub with data
     *
     * @return string
     * @throws ViewNotFoundException
     */
    public function render()
    {
        if ($this->renderedTex) {
            return $this->renderedTex;
        }

        if (!view()->exists($this->stubPath)) {
            throw new ViewNotFoundException('View ' . $this->stubPath . ' not found.');
        }

        $this->renderedTex = view($this->stubPath, $this->data)->render();

        return $this->renderedTex;
    }

    /**
     * Save generated PDF
     *
     * @param  string $location
     *
     * @return boolean
     */
    public function savePdf($location, $callback = null)
    {
        $this->render();

        $pdfPath = $this->generate($callback);

        $fileMoved = \File::move($pdfPath, $location);

        \Event::dispatch(new LatexPdfWasGenerated($location, 'savepdf', $this->metadata));

        return $fileMoved;
    }

    /**
     * Download file as a response
     *
     * @param  string|null $fileName
     * @return Illuminate\Http\Response
     */
    public function download($fileName = null, $callback = null)
    {
        if (!$this->isRaw) {
            $this->render();
        }

        $pdfPath = $this->generate($callback);

        if (!$fileName) {
            $fileName = basename($pdfPath);
        }

        \Event::dispatch(new LatexPdfWasGenerated($fileName, 'download', $this->metadata));

        return \Response::download($pdfPath, $fileName, [
              'Content-Type' => 'application/pdf',
        ]);
    }

    public function file($callback = null) {
        if (!$this->isRaw) {
            $this->render();
        }

        $pdfPath = $this->generate($callback);

        \Event::dispatch(new LatexPdfWasGenerated($pdfPath, 'file', $this->metadata));

        return \Response::file($pdfPath, [
              'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Generate the PDF
     *
     * @return string
     */
    private function generate($callback = null)
    {
        $fileName = Str::random(10);
        $tmpfname = tempnam(sys_get_temp_dir(), $fileName);
        $tmpDir = sys_get_temp_dir();
        chmod($tmpfname, 0755);
        
        if ($callback) {
            $callback($tmpDir, $fileName);
        }

        $auxFileName = $tmpDir . '/' . \File::name($tmpfname) . '.aux';
        $lastAuxHash = null;
        $auxHash = null;

        \File::put($tmpfname, $this->renderedTex);
        $program    = $this->binPath ? $this->binPath : 'pdflatex';
        $args = [$program];
        if ($this->runNonStop) {
            $args[] = '-interaction=nonstopmode';
        }
        $args = array_merge(
            $args,
            ['-output-directory', $tmpDir, $tmpfname]
        );
        
        do {
            $lastAuxHash = $auxHash;
            $process    = new Process($args, $tmpDir);
            $process->run();

            if (!$process->isSuccessful()) {
                \Event::dispatch(new LatexPdfFailed($fileName, 'download', $this->metadata));
                $this->parseError($tmpfname, $process);
            }

            if (\File::exists($auxFileName)) {
                $auxHash = \File::hash($auxFileName);
            }
        } while ($this->runUntilAuxSettles && ($lastAuxHash != $auxHash));

        $this->teardown($tmpfname);

        register_shutdown_function(function () use ($tmpfname) {
            if (\File::exists($tmpfname . '.pdf')) {
                \File::delete($tmpfname . '.pdf');
            }
        });

        return $tmpfname.'.pdf';
    }

    /**
     * Teardown secondary files
     *
     * @param  string $tmpfname
     *
     * @return void
     */
    private function teardown($tmpfname)
    {
        if (\File::exists($tmpfname)) {
            \File::delete($tmpfname);
        }
        if (\File::exists($tmpfname . '.aux')) {
            \File::delete($tmpfname . '.aux');
        }
        if (\File::exists($tmpfname . '.log')) {
            \File::delete($tmpfname . '.log');
        }

        return $this;
    }

    /**
     * Throw error from log gile
     *
     * @param  string $tmpfname
     *
     * @throws \LatextException
     */
    private function parseError($tmpfname, $process)
    {
        $logFile = $tmpfname.'.log';

        if (!\File::exists($logFile)) {
            throw new LatextException($process->getOutput());
        }

        $error = \File::get($tmpfname.'.log');
        throw new LatextException($error);
    }
}
