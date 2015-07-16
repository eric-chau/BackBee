<?php

namespace BackBee\Rest\Annotation\Handler;

use BackBee\Rest\Annotation\AnnotationHandlerInterface;
use BackBee\Rest\Annotation\Sort;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @author Eric Chau <eric.chau@lp-digital.fr>
 */
class SortHandler extends AbstractHandler
{
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * {@inheritdoc}
     */
    public function handle($annotation)
    {
        parent::handle($annotation);

        if (!$this->request->isMethod('GET') || !$this->request->query->has('sort')) {
            return;
        }

        if ($this->request->attributes->has('sort')) {
            throw new \LogicException('Request attribute "sort" already exists but SortHandler didn\'t handle it yet.');
        }

        $desc = $this->request->query->has('desc') ? explode(',', $this->request->query->get('desc')) : [];
        try {
            $this->throwExceptionOnInvalidFields($annotation->acceptedFields, $desc);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException(sprintf(
                'Invalid `desc` fields provided: "%s". Accepted fields: "%s".',
                $e->getMessage(),
                implode('", "', $annotation->acceptedFields)
            ));
        }


        $asc = explode(',', $this->request->query->get('sort'));
        try {
            $this->throwExceptionOnInvalidFields($annotation->acceptedFields, $asc);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException(sprintf(
                'You tried to sort collection by "%s" but this is not allowed. Accepted fields: "%s".',
                $e->getMessage(),
                implode('", "', $annotation->acceptedFields)
            ));
        }

        try {
            $this->throwExceptionOnInvalidFields($asc, $desc);
        } catch (\InvalidArgumentException $e) {
            throw new \LogicException(sprintf(
                'Cannot descending sort collection by "%s": missing from asc field list.',
                $e->getMessage()
            ));
        }

        $sort = [];
        foreach ($asc as $fieldName) {
            $sort[$fieldName] = in_array($fieldName, $desc) ? 'desc' : 'asc';
        }

        $this->request->attributes->set('sort', $sort);
    }

    /**
     * {@inheritdoc}
     */
    public function supports($annotation)
    {
        return $annotation instanceof Sort;
    }

    /**
     * Throws an exception if there is one or many invalid fields.
     *
     * @param  array  $acceptedFields
     * @param  array  $fieldsToValidate
     * @throws \InvalidArgumentException if provided fields to validate does not exist in accepted field list
     */
    private function throwExceptionOnInvalidFields(array $acceptedFields, array $fieldsToValidate)
    {
        $invalidFields = [];
        foreach ($fieldsToValidate as $field) {
            if (!in_array($field, $acceptedFields)) {
                $invalidFields[] = $field;
            }
        }

        if (0 < count($invalidFields)) {
            throw new \InvalidArgumentException(implode('", "', $invalidFields));
        }
    }
}
