<?php
/**
 * PatientFlowHelper [DEPRECATED]
 * This class is no longer needed as the system now uses Direct Real-Time Inference
 * from existing hospital tables (Visits, Admissions, etc.) to track patient flow.
 */
class PatientFlowHelper {
    public function __construct($pdo) {}
    public function updateLocation($patient_id, $location, $priority = null, $visit_id = null) { return true; }
    public function addProcessState($patient_id, $state) { return true; }
}
