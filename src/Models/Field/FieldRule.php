<?php
namespace Boostack\Models\Field;
/**
 * Boostack: FieldRule.php
 * ========================================================================
 * Copyright 2014-2025 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.0
 */
class FieldRule
{

    private \Boostack\Models\Field\Field $field;
    private ?bool $required = null;

    public function __construct($name, $type)
    {
        if (!FieldType::isValidValue($type)) {
            throw new \Exception("error: wrong field type");
        }
        $this->field = new Field($name, $type);
        $this->field->rules["name"] = $name;
        $this->field->rules["type"] = $type;
        $this->field->rules["required"] = false;
    }

    public function get(): array
    {
        if ($this->required) {
            $this->addRule("required", true);
        }
        return $this->field->rules;
    }

    public function getString(): string
    {
        $a = $this->get();
        $res = "";
        foreach ($a as $key => $value) {
            $res .= $key . ":" . $value . "|";
        }
        return substr($res, 0, -1);
    }

    public function required(): self
    {
        $this->required = true;
        return $this;
    }

    public function title($str): self
    {
        $this->addRule("title", $str);
        return $this;
    }

    public function placeholder($str): self
    {
        $this->addRule("placeholder", $str);
        return $this;
    }

    public function regex($str): self
    {
        $this->addRule("regex", $str);
        return $this;
    }

    public function defaultValue($val): self
    {
        $this->addRule("defaultValue", $val);
        return $this;
    }

    public function options(array $val): self
    {
        $this->constraint(array(FieldType::COMBO));
        $this->addRule("options", $val);
        return $this;
    }

    public function description($str): self
    {
        $this->addRule("description", $str);
        return $this;
    }

    public function max($upperbound): self
    {
        $this->constraint(array(FieldType::INTEGER, FieldType::FLOAT, FieldType::NUMERIC));
        $this->addRule("max", $upperbound);
        return $this;
    }

    public function min($lowerbound): self
    {
        $this->constraint(array(FieldType::INTEGER, FieldType::FLOAT, FieldType::NUMERIC));
        $this->addRule("min", $lowerbound);
        return $this;
    }

    public function min_length($min_length): self
    {
        $this->constraint(array(FieldType::STRING, FieldType::TEXT, FieldType::EMAIL, FieldType::USERNAME, FieldType::PASSWORD));
        $this->addRule("min_length", $min_length);
        return $this;
    }

    public function max_length($max_length): self
    {
        $this->constraint(array(FieldType::STRING, FieldType::TEXT, FieldType::EMAIL, FieldType::USERNAME, FieldType::PASSWORD));
        $this->addRule("max_length", $max_length);
        return $this;
    }

    public function from(\DateTime $d): self
    {
        $this->addRule("from", $d->format(\Boostack\Models\Config::get("default_datetime_format")));
        return $this;
    }

    public function to(\DateTime $d): self
    {
        $this->constraint(array(FieldType::DATE));
        $this->addRule("to", $d->format(\Boostack\Models\Config::get("default_datetime_format")));
        return $this;
    }

    private function constraint(array $types): void
    {
        if (!in_array($this->field->type, $types)) {
            $exception = new \Exception();
            $trace = $exception->getTrace();
            $final_call = $trace[1]["function"];
            throw new \Exception("error: you cannot call method '" . $final_call . "' on '" . $this->field->type . "' field type");
        }
    }

    private function addRule(string $name, $value): void
    {
        $this->field->rules[$name] = $value;
    }
}
