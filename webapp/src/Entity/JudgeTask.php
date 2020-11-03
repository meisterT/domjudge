<?php declare(strict_types=1);
namespace App\Entity;

use App\Doctrine\DBAL\Types\JudgeTaskType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Individual judge tasks.
 * TODO: Add indices.
 *
 * @ORM\Entity()
 * @ORM\Table(
 *     name="judgetask",
 *     options={"collate"="utf8mb4_unicode_ci", "charset"="utf8mb4", "comment"="Individual judge tasks."}
 *     )
 */
class JudgeTask
{
    /**
     * @var int
     *
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @ORM\Column(type="integer", name="judgetaskid", length=4,
     *     options={"comment"="Judgetask ID","unsigned"=true},
     *     nullable=false)
     */
    private $judgetaskid;

    /**
     * @var string
     * @ORM\Column(type="judge_task_type", name="type",
     *     options={"comment"="Type of the judge task.","default"="judging_run"},
     *     nullable=false)
     */
    private $type = JudgeTaskType::JUDGING_RUN;

    /**
     * @var int
     * @ORM\Column(type="integer", name="rank", length=4,
     *     options={"comment"="Priority; negative means higher priority",
     *              "unsigned"=false},
     *     nullable=false)
     */
    private $priority;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", name="submitid", length=4,
     *     options={"comment"="Submission ID being judged","unsigned"=true},
     *     nullable=true)
     * @Serializer\Type("string")
     */
    private $submitid;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", name="judgingrunid", length=4,
     *     options={"comment"="Corresponding judging run ID","unsigned"=true},
     *     nullable=true)
     * @Serializer\Type("string")
     */
    private $judgingrunid;

    // Note that we rely on the fact here that files with an ID are immutable,
    // so clients are allowed to cache them on disk.
    // TODO: Actually implement immutability :-)

    /**
     * @var int
     *
     * @ORM\Column(type="integer", name="compile_script_id", length=4,
     *     options={"comment"="Compile script ID","unsigned"=true},
     *     nullable=true)
     * @Serializer\Type("string")
     */
    private $compile_script_id;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", name="run_script_id", length=4,
     *     options={"comment"="Run script ID","unsigned"=true},
     *     nullable=true)
     * @Serializer\Type("string")
     */
    private $run_script_id;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", name="compare_script_id", length=4,
     *     options={"comment"="Compare script ID","unsigned"=true},
     *     nullable=true)
     * @Serializer\Type("string")
     */
    private $compare_script_id;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", name="input_id", length=4,
     *     options={"comment"="Input ID","unsigned"=true},
     *     nullable=true)
     * @Serializer\Type("string")
     */
    private $input_id;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", name="output_id", length=4,
     *     options={"comment"="Expected output ID","unsigned"=true},
     *     nullable=true)
     * @Serializer\Type("string")
     */
    private $output_id;

    /**
     * @var string
     * @ORM\Column(type="text", name="compile_config",
     *     options={"comment"="The compile config as JSON-blob.",
     *              "collation"="utf8mb4_bin", "default"="NULL"},
     *     nullable=true)
     */
    protected $compile_config;

    /**
     * @var string
     * @ORM\Column(type="text", name="run_config",
     *     options={"comment"="The run config as JSON-blob.",
     *              "collation"="utf8mb4_bin", "default"="NULL"},
     *     nullable=true)
     */
    protected $run_config;

    /**
     * @var string
     * @ORM\Column(type="text", name="compare_config",
     *     options={"comment"="The compare config as JSON-blob.",
     *              "collation"="utf8mb4_bin", "default"="NULL"},
     *     nullable=true)
     */
    protected $compare_config;

    /**
     * Get judgetaskid
     *
     * @return integer
     */
    public function getJudgetaskid()
    {
        return $this->judgetaskid;
    }

    /**
     * Set type
     *
     * @param string $type
     *
     * @return JudgeTask
     */
    public function setType($type)
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Get type
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Set priority
     *
     * @param string $priority
     *
     * @return JudgeTask
     */
    public function setPriority(string $priority)
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Get priority
     *
     * @return string
     */
    public function getPriority()
    {
        return $this->priority;
    }

    /**
     * Set submitid
     *
     * @param integer $submitid
     *
     * @return JudgeTask
     */
    public function setSubmitid($submitid)
    {
        $this->submitid = $submitid;

        return $this;
    }

    /**
     * Get submitid
     *
     * @return integer
     */
    public function getSubmitid()
    {
        return $this->submitid;
    }

    /**
     * Set judgingrunid
     *
     * @param integer $judgingrunid
     *
     * @return JudgeTask
     */
    public function setJudgingRunId(int $judgingrunid)
    {
        $this->judgingrunid = $judgingrunid;

        return $this;
    }

    /**
     * Get judgingrunid
     *
     * @return integer
     */
    public function getJudgingRunId()
    {
        return $this->judgingrunid;
    }

    /**
     * Set compile_script_id
     *
     * @param integer $compile_script_id
     *
     * @return JudgeTask
     */
    public function setCompileScriptId(int $compile_script_id)
    {
        $this->compile_script_id = $compile_script_id;

        return $this;
    }

    /**
     * Get compile_script_id
     *
     * @return integer
     */
    public function getCompileScriptId()
    {
        return $this->compile_script_id;
    }

    /**
     * Set run_script_id
     *
     * @param integer $run_script_id
     *
     * @return JudgeTask
     */
    public function setRunScriptId(int $run_script_id)
    {
        $this->run_script_id = $run_script_id;

        return $this;
    }

    /**
     * Get run_script_id
     *
     * @return integer
     */
    public function getRunScriptId()
    {
        return $this->run_script_id;
    }

    /**
     * Set compare_script_id
     *
     * @param integer $compare_script_id
     *
     * @return JudgeTask
     */
    public function setCompareScriptId(int $compare_script_id)
    {
        $this->compare_script_id = $compare_script_id;

        return $this;
    }

    /**
     * Get compare_script_id
     *
     * @return integer
     */
    public function getCompareScriptId()
    {
        return $this->compare_script_id;
    }

    /**
     * Set input_id
     *
     * @param integer $input_id
     *
     * @return JudgeTask
     */
    public function setInputId(int $input_id)
    {
        $this->input_id = $input_id;

        return $this;
    }

    /**
     * Get input_id
     *
     * @return integer
     */
    public function getInputId()
    {
        return $this->input_id;
    }

    /**
     * Set output_id
     *
     * @param integer $output_id
     *
     * @return JudgeTask
     */
    public function setOutputId(int $output_id)
    {
        $this->output_id = $output_id;

        return $this;
    }

    /**
     * Get output_id
     *
     * @return integer
     */
    public function getOutputId()
    {
        return $this->output_id;
    }

    /**
     * Set compile_config
     *
     * @param string $compile_config
     *
     * @return JudgeTask
     */
    public function setCompileConfig(string $compile_config)
    {
        $this->compile_config = $compile_config;

        return $this;
    }

    /**
     * Get compile_config
     *
     * @return string
     */
    public function getCompileConfig()
    {
        return $this->compile_config;
    }

    /**
     * Set run_config
     *
     * @param string $run_config
     *
     * @return JudgeTask
     */
    public function setRunConfig(string $run_config)
    {
        $this->run_config = $run_config;

        return $this;
    }

    /**
     * Get run_config
     *
     * @return string
     */
    public function getRunConfig()
    {
        return $this->run_config;
    }

    /**
     * Set compare_config
     *
     * @param string $compare_config
     *
     * @return JudgeTask
     */
    public function setCompareConfig(string $compare_config)
    {
        $this->compare_config = $compare_config;

        return $this;
    }

    /**
     * Get compare_config
     *
     * @return string
     */
    public function getCompareConfig()
    {
        return $this->compare_config;
    }
}
