<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Console\Helper;

use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Dialog\Question;
use Symfony\Component\Console\Dialog\ChoiceQuestion;

/**
 * The Question class provides helpers to interact with the user.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class QuestionHelper extends Helper
{
    private $inputStream;
    private static $shell;
    private static $stty;

    public function __construct()
    {
        $this->inputStream = STDIN;
    }

    /**
     * Asks a question to the user.
     *
     * @param OutputInterface $output   An Output instance
     * @param Question        $question The question to ask
     *
     * @return string The user answer
     *
     * @throws \RuntimeException If there is no data to read in the input stream
     */
    public function ask(OutputInterface $output, Question $question)
    {
        $that = $this;

        if (!$question->getValidator()) {
            return $that->doAsk($output, $question);
        }

        $interviewer = function() use ($output, $question, $that) {
            return $that->doAsk($output, $question);
        };

        return $this->validateAttempts($interviewer, $output, $question);
    }

    /**
     * Sets the input stream to read from when interacting with the user.
     *
     * This is mainly useful for testing purpose.
     *
     * @param resource $stream The input stream
     */
    public function setInputStream($stream)
    {
        $this->inputStream = $stream;
    }

    /**
     * Returns the helper's input stream
     *
     * @return string
     */
    public function getInputStream()
    {
        return $this->inputStream;
    }

    private function doAsk($output, $question)
    {
        $message = $question->getQuestion();
        if ($question instanceof ChoiceQuestion) {
            $width = max(array_map('strlen', array_keys($question->getChoices())));

            $messages = (array) $question->getQuestion();
            foreach ($question->getChoices() as $key => $value) {
                $messages[] = sprintf("  [<info>%-${width}s</info>] %s", $key, $value);
            }

            $output->writeln($messages);

            $message = $question->getPrompt();
        }

        $output->write($message);

        $autocomplete = $question->getAutocompleter();
        if (null === $autocomplete || !$this->hasSttyAvailable()) {
            $ret = false;
            if ($question->isHidden()) {
                try {
                    $ret = trim($this->askHiddenResponse($output, $question));
                } catch (\RuntimeException $e) {
                    if (!$question->isHiddenFallback()) {
                        throw $e;
                    }
                }
            }

            if (false === $ret) {
                $ret = fgets($this->inputStream, 4096);
                if (false === $ret) {
                    throw new \RuntimeException('Aborted');
                }
                $ret = trim($ret);
            }
        } else {
            $ret = $this->autocomplete($output, $question);
        }

        $ret = strlen($ret) > 0 ? $ret : $question->getDefault();

        if ($normalizer = $question->getNormalizer()) {
            return $normalizer($ret);
        }

        return $ret;
    }

    private function autocomplete(OutputInterface $output, Question $question)
    {
        $autocomplete = $question->getAutocompleter();
        $ret = '';

        $i = 0;
        $ofs = -1;
        $matches = $autocomplete;
        $numMatches = count($matches);

        $sttyMode = shell_exec('stty -g');

        // Disable icanon (so we can fread each keypress) and echo (we'll do echoing here instead)
        shell_exec('stty -icanon -echo');

        // Add highlighted text style
        $output->getFormatter()->setStyle('hl', new OutputFormatterStyle('black', 'white'));

        // Read a keypress
        while (!feof($this->inputStream)) {
            $c = fread($this->inputStream, 1);

            // Backspace Character
            if ("\177" === $c) {
                if (0 === $numMatches && 0 !== $i) {
                    $i--;
                    // Move cursor backwards
                    $output->write("\033[1D");
                }

                if ($i === 0) {
                    $ofs = -1;
                    $matches = $autocomplete;
                    $numMatches = count($matches);
                } else {
                    $numMatches = 0;
                }

                // Pop the last character off the end of our string
                $ret = substr($ret, 0, $i);
            } elseif ("\033" === $c) { // Did we read an escape sequence?
                $c .= fread($this->inputStream, 2);

                // A = Up Arrow. B = Down Arrow
                if ('A' === $c[2] || 'B' === $c[2]) {
                    if ('A' === $c[2] && -1 === $ofs) {
                        $ofs = 0;
                    }

                    if (0 === $numMatches) {
                        continue;
                    }

                    $ofs += ('A' === $c[2]) ? -1 : 1;
                    $ofs = ($numMatches + $ofs) % $numMatches;
                }
            } elseif (ord($c) < 32) {
                if ("\t" === $c || "\n" === $c) {
                    if ($numMatches > 0 && -1 !== $ofs) {
                        $ret = $matches[$ofs];
                        // Echo out remaining chars for current match
                        $output->write(substr($ret, $i));
                        $i = strlen($ret);
                    }

                    if ("\n" === $c) {
                        $output->write($c);
                        break;
                    }

                    $numMatches = 0;
                }

                continue;
            } else {
                $output->write($c);
                $ret .= $c;
                $i++;

                $numMatches = 0;
                $ofs = 0;

                foreach ($autocomplete as $value) {
                    // If typed characters match the beginning chunk of value (e.g. [AcmeDe]moBundle)
                    if (0 === strpos($value, $ret) && $i !== strlen($value)) {
                        $matches[$numMatches++] = $value;
                    }
                }
            }

            // Erase characters from cursor to end of line
            $output->write("\033[K");

            if ($numMatches > 0 && -1 !== $ofs) {
                // Save cursor position
                $output->write("\0337");
                // Write highlighted text
                $output->write('<hl>'.substr($matches[$ofs], $i).'</hl>');
                // Restore cursor position
                $output->write("\0338");
            }
        }

        // Reset stty so it behaves normally again
        shell_exec(sprintf('stty %s', $sttyMode));

        return $ret;
    }

    /**
     * Asks a question to the user, the response is hidden
     *
     * @param OutputInterface $output   An Output instance
     * @param string|array    $question The question
     *
     * @return string         The answer
     *
     * @throws \RuntimeException In case the fallback is deactivated and the response can not be hidden
     */
    private function askHiddenResponse(OutputInterface $output, Question $question)
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $exe = __DIR__.'/../Resources/bin/hiddeninput.exe';

            // handle code running from a phar
            if ('phar:' === substr(__FILE__, 0, 5)) {
                $tmpExe = sys_get_temp_dir().'/hiddeninput.exe';
                copy($exe, $tmpExe);
                $exe = $tmpExe;
            }

            $value = rtrim(shell_exec($exe));
            $output->writeln('');

            if (isset($tmpExe)) {
                unlink($tmpExe);
            }

            return $value;
        }

        if ($this->hasSttyAvailable()) {
            $sttyMode = shell_exec('stty -g');

            shell_exec('stty -echo');
            $value = fgets($this->inputStream, 4096);
            shell_exec(sprintf('stty %s', $sttyMode));

            if (false === $value) {
                throw new \RuntimeException('Aborted');
            }

            $value = trim($value);
            $output->writeln('');

            return $value;
        }

        if (false !== $shell = $this->getShell()) {
            $readCmd = $shell === 'csh' ? 'set mypassword = $<' : 'read -r mypassword';
            $command = sprintf("/usr/bin/env %s -c 'stty -echo; %s; stty echo; echo \$mypassword'", $shell, $readCmd);
            $value = rtrim(shell_exec($command));
            $output->writeln('');

            return $value;
        }

        throw new \RuntimeException('Unable to hide the response');
    }

    /**
     * Validates an attempt.
     *
     * @param callable        $interviewer  A callable that will ask for a question and return the result
     * @param OutputInterface $output       An Output instance
     * @param Question        $question     A Question instance
     *
     * @return string   The validated response
     *
     * @throws \Exception In case the max number of attempts has been reached and no valid response has been given
     */
    private function validateAttempts($interviewer, OutputInterface $output, Question $question)
    {
        $error = null;
        $attempts = $question->getMaxAttemps();
        while (false === $attempts || $attempts--) {
            if (null !== $error) {
                $output->writeln($this->getHelperSet()->get('formatter')->formatBlock($error->getMessage(), 'error'));
            }

            try {
                return call_user_func($question->getValidator(), $interviewer());
            } catch (\Exception $error) {
            }
        }

        throw $error;
    }

    /**
     * Return a valid unix shell
     *
     * @return string|Boolean  The valid shell name, false in case no valid shell is found
     */
    private function getShell()
    {
        if (null !== self::$shell) {
            return self::$shell;
        }

        self::$shell = false;

        if (file_exists('/usr/bin/env')) {
            // handle other OSs with bash/zsh/ksh/csh if available to hide the answer
            $test = "/usr/bin/env %s -c 'echo OK' 2> /dev/null";
            foreach (array('bash', 'zsh', 'ksh', 'csh') as $sh) {
                if ('OK' === rtrim(shell_exec(sprintf($test, $sh)))) {
                    self::$shell = $sh;
                    break;
                }
            }
        }

        return self::$shell;
    }

    private function hasSttyAvailable()
    {
        if (null !== self::$stty) {
            return self::$stty;
        }

        exec('stty 2>&1', $output, $exitcode);

        return self::$stty = $exitcode === 0;
    }

    /**
     * {@inheritDoc}
     */
    public function getName()
    {
        return 'question';
    }
}