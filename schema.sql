-- Public demo schema only. No source rows, accounts, documents or telemetry.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE `assessments` (
  `id` int(11) NOT NULL,
  `lrn` varchar(12) NOT NULL,
  `enrollment_id` int(11) DEFAULT NULL,
  `assessment_date` date NOT NULL,
  `assessment_type` enum('Initial','Quarterly','Annual','Exit') NOT NULL,
  `assessment_stage` enum('Pre-Assessment','Post-Assessment','Review','Exit') DEFAULT NULL,
  `document_type` enum('IEP','ILP') DEFAULT NULL,
  `iep_goals` text DEFAULT NULL,
  `ilp_goals` text DEFAULT NULL,
  `review_date` date DEFAULT NULL,
  `assessor_name` varchar(255) NOT NULL,
  `academic_rating` enum('Not Yet','Developing','Satisfactory','Proficient') DEFAULT NULL,
  `social_skills_rating` enum('Not Yet','Developing','Satisfactory','Proficient') DEFAULT NULL,
  `life_skills_rating` enum('Not Yet','Developing','Satisfactory','Proficient') DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `behavior_intervention_reports` (
  `id` int(11) NOT NULL,
  `lrn` varchar(12) NOT NULL,
  `school_year` varchar(20) NOT NULL,
  `quarter` enum('Q1','Q2','Q3','Q4') NOT NULL,
  `school_id` int(11) NOT NULL,
  `date_of_observation` date NOT NULL,
  `teacher_name` varchar(255) NOT NULL,
  `parent_guardian_conforme` varchar(255) DEFAULT NULL,
  `baseline_data` text DEFAULT NULL,
  `difficulty_disability` text DEFAULT NULL,
  `with_medical_assessment` tinyint(1) DEFAULT 0,
  `medical_results` text DEFAULT NULL,
  `educational_placement` enum('Self-Contained','Inclusion') DEFAULT NULL,
  `antecedent_home` text DEFAULT NULL,
  `antecedent_school` text DEFAULT NULL,
  `antecedent_other` text DEFAULT NULL,
  `observable_home` text DEFAULT NULL,
  `observable_school` text DEFAULT NULL,
  `observable_other` text DEFAULT NULL,
  `consequence_home` text DEFAULT NULL,
  `consequence_school` text DEFAULT NULL,
  `consequence_other` text DEFAULT NULL,
  `interventions_done` text DEFAULT NULL,
  `proactive_strategies` text DEFAULT NULL,
  `reactive_strategies` text DEFAULT NULL,
  `targeted_behavior` text DEFAULT NULL,
  `specific_objectives` text DEFAULT NULL,
  `basic_intervention` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `documents` (
  `id` int(11) NOT NULL,
  `lrn` varchar(12) NOT NULL,
  `document_type` enum('IEP','ILP','ITP','SF9','Form137','Assessment','Medical','Referral','Transfer Support','Other') DEFAULT 'Other',
  `original_filename` varchar(255) NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `file_size` int(11) DEFAULT 0,
  `mime_type` varchar(100) DEFAULT NULL,
  `extracted_text` longtext DEFAULT NULL,
  `assessment_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `enrollments` (
  `id` int(11) NOT NULL,
  `lrn` varchar(12) NOT NULL,
  `school_id` int(11) NOT NULL,
  `school_year` varchar(20) NOT NULL,
  `placement_type` enum('Graded','Non-Graded') NOT NULL,
  `self_contained_level` varchar(20) DEFAULT NULL,
  `is_historical` tinyint(1) DEFAULT 0,
  `grade_level` varchar(50) NOT NULL,
  `primary_diagnosis` varchar(150) NOT NULL,
  `remarks` text DEFAULT NULL,
  `updated_by` int(11) NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0,
  `status` varchar(50) DEFAULT 'active',
  `transfer_date` datetime DEFAULT NULL,
  `previous_school_name` varchar(255) DEFAULT NULL,
  `years_in_school` varchar(50) DEFAULT NULL,
  `current_level_of_education` varchar(255) DEFAULT NULL,
  `interpreter_needed` varchar(255) DEFAULT NULL,
  `assessment_mode` varchar(50) DEFAULT NULL,
  `assessment_institution` varchar(255) DEFAULT NULL,
  `medical_results` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `enrollment_difficulties` (
  `id` int(11) NOT NULL,
  `enrollment_id` int(11) NOT NULL,
  `difficulty_key` varchar(64) NOT NULL,
  `difficulty_label` varchar(255) NOT NULL,
  `other_text` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `enrollment_disabilities` (
  `id` int(11) NOT NULL,
  `enrollment_id` int(11) NOT NULL,
  `category` enum('diagnosed','difficulty') NOT NULL,
  `disability` varchar(150) NOT NULL,
  `other_text` varchar(255) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `guardians` (
  `id` int(11) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `extension_name` varchar(20) DEFAULT NULL,
  `sex` enum('Male','Female') NOT NULL,
  `relationship` varchar(50) DEFAULT NULL,
  `contact_number` varchar(50) NOT NULL,
  `secondary_contact_number` varchar(50) DEFAULT NULL,
  `house_no_street` varchar(255) NOT NULL,
  `barangay` varchar(150) NOT NULL,
  `region` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `mother_last_name` varchar(100) DEFAULT NULL,
  `mother_first_name` varchar(100) DEFAULT NULL,
  `mother_middle_name` varchar(100) DEFAULT NULL,
  `mother_extension_name` varchar(20) DEFAULT NULL,
  `mother_sex` enum('Male','Female') DEFAULT NULL,
  `mother_contact_number` varchar(50) DEFAULT NULL,
  `mother_house_no_street` varchar(255) DEFAULT NULL,
  `mother_barangay` varchar(150) DEFAULT NULL,
  `mother_region` varchar(100) DEFAULT NULL,
  `mother_province` varchar(100) DEFAULT NULL,
  `mother_city` varchar(100) DEFAULT NULL,
  `mother_same_as_guardian` tinyint(1) DEFAULT 0,
  `no_mother` tinyint(1) DEFAULT 0,
  `father_last_name` varchar(100) DEFAULT NULL,
  `father_first_name` varchar(100) DEFAULT NULL,
  `father_middle_name` varchar(100) DEFAULT NULL,
  `father_extension_name` varchar(20) DEFAULT NULL,
  `father_sex` enum('Male','Female') DEFAULT NULL,
  `father_contact_number` varchar(50) DEFAULT NULL,
  `father_house_no_street` varchar(255) DEFAULT NULL,
  `father_barangay` varchar(150) DEFAULT NULL,
  `father_region` varchar(100) DEFAULT NULL,
  `father_province` varchar(100) DEFAULT NULL,
  `father_city` varchar(100) DEFAULT NULL,
  `father_same_as_guardian` tinyint(1) DEFAULT 0,
  `no_father` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `workplace` varchar(255) DEFAULT NULL,
  `landline` varchar(50) DEFAULT NULL,
  `mother_tongue` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ilp_goals` (
  `id` int(11) NOT NULL,
  `ilp_id` int(11) NOT NULL,
  `interest` text DEFAULT NULL,
  `goal` text NOT NULL,
  `interventions` text DEFAULT NULL,
  `timeline` varchar(100) DEFAULT NULL,
  `persons_responsible` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `progress_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ilp_team_members` (
  `id` int(11) NOT NULL,
  `ilp_id` int(11) NOT NULL,
  `role` varchar(100) DEFAULT NULL,
  `full_name` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learners` (
  `lrn` varchar(12) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `extension_name` varchar(20) DEFAULT NULL,
  `birthday` date NOT NULL,
  `sex` enum('Male','Female') NOT NULL,
  `guardian_id` int(11) NOT NULL,
  `pwd_id_number` varchar(100) DEFAULT NULL,
  `profile_id` varchar(50) DEFAULT NULL,
  `house_no_street` varchar(255) DEFAULT NULL,
  `barangay` varchar(150) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `same_as_guardian_address` tinyint(1) DEFAULT 1,
  `is_deleted` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `religion` varchar(100) DEFAULT NULL,
  `mother_tongue` varchar(100) DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learner_guardians` (
  `id` int(11) NOT NULL,
  `lrn` varchar(12) NOT NULL,
  `relation_type` enum('Parent','Guardian','Sibling','Relative','Legal Guardian','Other') NOT NULL DEFAULT 'Guardian',
  `full_name` varchar(255) NOT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `occupation` varchar(255) DEFAULT NULL,
  `is_primary_contact` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learner_iep` (
  `id` int(11) NOT NULL,
  `learner_id` varchar(12) NOT NULL,
  `enrollment_id` int(11) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `meeting_date` date DEFAULT NULL,
  `last_iep_date` date DEFAULT NULL,
  `iep_type` varchar(50) DEFAULT NULL,
  `diff_seeing` tinyint(1) DEFAULT 0,
  `diff_hearing` tinyint(1) DEFAULT 0,
  `diff_communicating` tinyint(1) DEFAULT 0,
  `diff_mobility` tinyint(1) DEFAULT 0,
  `diff_interpersonal` tinyint(1) DEFAULT 0,
  `diff_adaptive` tinyint(1) DEFAULT 0,
  `diff_basic_skills` tinyint(1) DEFAULT 0,
  `diff_remembering` tinyint(1) DEFAULT 0,
  `diff_others` varchar(255) DEFAULT NULL,
  `team_psychologist` varchar(255) DEFAULT NULL,
  `team_guidance` varchar(255) DEFAULT NULL,
  `team_nurse` varchar(255) DEFAULT NULL,
  `team_therapist` varchar(255) DEFAULT NULL,
  `team_speech_pathologist` varchar(255) DEFAULT NULL,
  `team_other` text DEFAULT NULL,
  `present_eval_results` text DEFAULT NULL,
  `academic_strengths` text DEFAULT NULL,
  `academic_needs` text DEFAULT NULL,
  `parental_concerns` text DEFAULT NULL,
  `disability_impact` text DEFAULT NULL,
  `sc_behavior` tinyint(1) DEFAULT 0,
  `sc_behavior_plan` tinyint(1) DEFAULT 0,
  `sc_mobility` tinyint(1) DEFAULT 0,
  `sc_vision` tinyint(1) DEFAULT 0,
  `sc_communication` tinyint(1) DEFAULT 0,
  `sc_attention` tinyint(1) DEFAULT 0,
  `sc_memory` tinyint(1) DEFAULT 0,
  `sc_hearing` tinyint(1) DEFAULT 0,
  `sc_assistive_tech` tinyint(1) DEFAULT 0,
  `sc_alt_format` tinyint(1) DEFAULT 0,
  `sc_notes` text DEFAULT NULL,
  `barriers_notes` text DEFAULT NULL,
  `accommodations` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learner_iep_annex6` (
  `id` int(11) NOT NULL,
  `learner_id` varchar(12) NOT NULL,
  `enrollment_id` int(11) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `meeting_date` date DEFAULT NULL,
  `date_of_last_iep` date DEFAULT NULL,
  `iep_review_date` date DEFAULT NULL,
  `purpose_of_meeting` varchar(100) DEFAULT NULL,
  `meeting_comments` text DEFAULT NULL,
  `interpreter_needed` varchar(255) DEFAULT NULL,
  `medical_diagnosis` tinyint(1) DEFAULT 0,
  `medical_diagnosis_specify` varchar(255) DEFAULT NULL,
  `diff_seeing` tinyint(1) DEFAULT 0,
  `diff_hearing` tinyint(1) DEFAULT 0,
  `diff_communicating` tinyint(1) DEFAULT 0,
  `diff_mobility` tinyint(1) DEFAULT 0,
  `diff_interpersonal` tinyint(1) DEFAULT 0,
  `diff_adaptive` tinyint(1) DEFAULT 0,
  `diff_basic_skills` tinyint(1) DEFAULT 0,
  `diff_remembering` tinyint(1) DEFAULT 0,
  `diff_others` varchar(255) DEFAULT NULL,
  `iep_team_json` text DEFAULT NULL,
  `learner_signature_date` date DEFAULT NULL,
  `parent_signature_date` date DEFAULT NULL,
  `rights_notified` tinyint(1) DEFAULT 0,
  `present_eval_results` text DEFAULT NULL,
  `academic_strengths` text DEFAULT NULL,
  `academic_needs` text DEFAULT NULL,
  `parental_concerns` text DEFAULT NULL,
  `disability_impact` text DEFAULT NULL,
  `special_factors_json` text DEFAULT NULL,
  `transition_home_to_school` tinyint(1) DEFAULT 0,
  `transition_school_to_life` tinyint(1) DEFAULT 0,
  `transition_sped_to_inclusion` tinyint(1) DEFAULT 0,
  `transition_grade_to_grade` tinyint(1) DEFAULT 0,
  `transition_school_to_work` tinyint(1) DEFAULT 0,
  `transition_class_to_class` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learner_iep_annex6_rows` (
  `id` int(11) NOT NULL,
  `iep_id` int(11) NOT NULL,
  `section_type` enum('barrier','goal','transition') NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `interest` varchar(255) DEFAULT NULL,
  `difficulty` varchar(255) DEFAULT NULL,
  `barrier_qualifier` varchar(20) DEFAULT NULL,
  `facilitator` text DEFAULT NULL,
  `facilitator_qualifier` varchar(20) DEFAULT NULL,
  `accommodations` text DEFAULT NULL,
  `goal` text DEFAULT NULL,
  `interventions` text DEFAULT NULL,
  `timeline` varchar(100) DEFAULT NULL,
  `responsible` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `progress` text DEFAULT NULL,
  `work_opportunities` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learner_iep_goals` (
  `id` int(11) NOT NULL,
  `iep_id` int(11) NOT NULL,
  `type` enum('goal','transition','barrier') DEFAULT 'goal',
  `interest` text DEFAULT NULL,
  `goal` text DEFAULT NULL,
  `interventions` text DEFAULT NULL,
  `timeline` varchar(100) DEFAULT NULL,
  `responsible` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `progress` text DEFAULT NULL,
  `work_opportunities` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learner_ilp` (
  `id` int(11) NOT NULL,
  `learner_id` varchar(12) NOT NULL,
  `enrollment_id` int(11) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `adviser_id` int(11) DEFAULT NULL,
  `interview_date` date DEFAULT NULL,
  `interview_guardian_name` varchar(255) DEFAULT NULL,
  `interview_guardian_contact` varchar(50) DEFAULT NULL,
  `interests_hobbies` text DEFAULT NULL,
  `daily_living_strengths` text DEFAULT NULL,
  `daily_living_needs` text DEFAULT NULL,
  `language_strengths` text DEFAULT NULL,
  `language_needs` text DEFAULT NULL,
  `psychomotor_strengths` text DEFAULT NULL,
  `psychomotor_needs` text DEFAULT NULL,
  `cognitive_strengths` text DEFAULT NULL,
  `cognitive_needs` text DEFAULT NULL,
  `aesthetic_strengths` text DEFAULT NULL,
  `aesthetic_needs` text DEFAULT NULL,
  `behavioral_strengths` text DEFAULT NULL,
  `behavioral_needs` text DEFAULT NULL,
  `orientation_strengths` text DEFAULT NULL,
  `orientation_needs` text DEFAULT NULL,
  `priority_needs` text DEFAULT NULL,
  `transition_package` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `present_level_results` text DEFAULT NULL,
  `parental_concerns` text DEFAULT NULL,
  `disability_impact` text DEFAULT NULL,
  `special_factors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `barriers_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `transition_interests` text DEFAULT NULL,
  `transition_work_opportunities` text DEFAULT NULL,
  `transition_skills` text DEFAULT NULL,
  `document_type` enum('IEP','ILP') NOT NULL DEFAULT 'ILP',
  `meeting_date` date DEFAULT NULL,
  `review_date` date DEFAULT NULL,
  `meeting_purpose` varchar(100) DEFAULT NULL,
  `rights_transfer_notified` tinyint(1) DEFAULT 0,
  `rights_not_applicable` tinyint(1) DEFAULT 0,
  `adviser_name` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learner_ilp_assessments` (
  `id` int(11) NOT NULL,
  `ilp_id` int(11) NOT NULL,
  `assessment_type` varchar(100) DEFAULT NULL,
  `date_administered` date DEFAULT NULL,
  `chronological_age` varchar(20) DEFAULT NULL,
  `administrator` varchar(255) DEFAULT NULL,
  `results` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learner_ilp_therapy_services` (
  `id` int(11) NOT NULL,
  `ilp_id` int(11) NOT NULL,
  `center_name` varchar(255) DEFAULT NULL,
  `service_type` varchar(255) DEFAULT NULL,
  `provider_name` varchar(255) DEFAULT NULL,
  `frequency_schedule` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learner_indicator_ratings` (
  `id` int(11) NOT NULL,
  `learner_id` varchar(12) NOT NULL,
  `enrollment_id` int(11) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `domain` varchar(100) DEFAULT NULL,
  `subdomain` varchar(100) DEFAULT NULL,
  `indicator` text NOT NULL,
  `q1` enum('D','AP','P','B','NO','NA') DEFAULT NULL,
  `q2` enum('D','AP','P','B','NO','NA') DEFAULT NULL,
  `q3` enum('D','AP','P','B','NO','NA') DEFAULT NULL,
  `q4` enum('D','AP','P','B','NO','NA') DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learner_itp` (
  `id` int(11) NOT NULL,
  `learner_id` varchar(12) NOT NULL,
  `enrollment_id` int(11) DEFAULT NULL,
  `school_year` varchar(20) NOT NULL,
  `plan_date` date DEFAULT NULL,
  `review_date` date DEFAULT NULL,
  `target_exit_date` date DEFAULT NULL,
  `parent_guardian_name` varchar(255) DEFAULT NULL,
  `current_level_of_education` varchar(255) DEFAULT NULL,
  `previous_school_name` varchar(255) DEFAULT NULL,
  `years_in_school` varchar(100) DEFAULT NULL,
  `religion` varchar(100) DEFAULT NULL,
  `current_address` text DEFAULT NULL,
  `contact_number` varchar(100) DEFAULT NULL,
  `assessment_mode` varchar(50) DEFAULT NULL,
  `assessment_institution` varchar(255) DEFAULT NULL,
  `strengths` text DEFAULT NULL,
  `interests` text DEFAULT NULL,
  `talents` text DEFAULT NULL,
  `skills` text DEFAULT NULL,
  `needs` text DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  `transition_goal` text DEFAULT NULL,
  `entry_point_label` varchar(255) DEFAULT NULL,
  `learning_packages` text DEFAULT NULL,
  `prepared_by_name` varchar(255) DEFAULT NULL,
  `prepared_by_title` varchar(255) DEFAULT NULL,
  `noted_by_name` varchar(255) DEFAULT NULL,
  `noted_by_title` varchar(255) DEFAULT NULL,
  `learner_strengths` text DEFAULT NULL,
  `learner_preferences` text DEFAULT NULL,
  `post_school_goal` text DEFAULT NULL,
  `transition_needs` text DEFAULT NULL,
  `family_supports` text DEFAULT NULL,
  `community_linkages` text DEFAULT NULL,
  `follow_up_notes` text DEFAULT NULL,
  `team_json` text DEFAULT NULL,
  `transition_home_to_school` tinyint(1) DEFAULT 0,
  `transition_school_to_life` tinyint(1) DEFAULT 0,
  `transition_sped_to_inclusion` tinyint(1) DEFAULT 0,
  `transition_grade_to_grade` tinyint(1) DEFAULT 0,
  `transition_school_to_work` tinyint(1) DEFAULT 0,
  `transition_class_to_class` tinyint(1) DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learner_itp_goal_rows` (
  `id` int(11) NOT NULL,
  `itp_id` int(11) NOT NULL,
  `competency` text DEFAULT NULL,
  `activities` text DEFAULT NULL,
  `time_frame` varchar(255) DEFAULT NULL,
  `persons_responsible` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learner_itp_plans` (
  `id` int(11) NOT NULL,
  `itp_id` int(11) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `interest` varchar(255) DEFAULT NULL,
  `work_opportunities` text DEFAULT NULL,
  `interventions` text DEFAULT NULL,
  `timeline` varchar(100) DEFAULT NULL,
  `responsible` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learner_itp_team_members` (
  `id` int(11) NOT NULL,
  `itp_id` int(11) NOT NULL,
  `role` varchar(255) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `contact_details` varchar(255) DEFAULT NULL,
  `date_started_with_team` date DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learner_skill_ratings` (
  `id` int(11) NOT NULL,
  `lrn` varchar(12) NOT NULL,
  `assessment_id` int(11) NOT NULL,
  `skill_category_id` int(11) NOT NULL,
  `skill_name` varchar(255) NOT NULL,
  `rating` enum('Not Yet','Developing','Satisfactory','Proficient','P','AP','D','B','NO/NA') DEFAULT NULL,
  `rating_scale` enum('IEP','Annex2') NOT NULL DEFAULT 'IEP',
  `remarks` text DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `rated_by` int(11) DEFAULT NULL,
  `rated_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learner_teachers` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `lrn` varchar(12) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learner_transfers` (
  `id` int(11) NOT NULL,
  `learner_id` varchar(12) NOT NULL,
  `from_school_id` int(11) NOT NULL,
  `to_school_id` int(11) NOT NULL,
  `transfer_date` date NOT NULL,
  `transfer_request_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `parental_consents` (
  `id` int(11) NOT NULL,
  `lrn` varchar(12) NOT NULL,
  `consent_type` enum('Assessment','IEP','Program Enrollment') NOT NULL,
  `consented_by` varchar(255) NOT NULL,
  `consent_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schools` (
  `id` int(11) NOT NULL,
  `school_name` varchar(150) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `district` varchar(100) NOT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `grade_levels_offered` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `school_principals` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `principal_name` varchar(255) NOT NULL,
  `effective_from` date NOT NULL,
  `effective_until` date DEFAULT NULL,
  `recorded_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sf9_attendance` (
  `id` int(11) NOT NULL,
  `report_card_id` int(11) NOT NULL,
  `month_order` tinyint(4) NOT NULL,
  `month_label` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `school_days` smallint(6) DEFAULT NULL,
  `days_present` smallint(6) DEFAULT NULL,
  `days_absent` smallint(6) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE `sf9_quarter_notes` (
  `id` int(11) NOT NULL,
  `report_card_id` int(11) NOT NULL,
  `quarter` tinyint(4) NOT NULL,
  `teacher_remarks` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent_comments` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent_signature` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE `sf9_report_cards` (
  `id` int(11) NOT NULL,
  `lrn` varchar(12) NOT NULL,
  `school_year` varchar(20) NOT NULL,
  `assessment_status` enum('With','Without') DEFAULT NULL,
  `admitted_to` varchar(255) DEFAULT NULL,
  `eligible_for_admission_to` varchar(255) DEFAULT NULL,
  `transfer_teacher_name` varchar(255) DEFAULT NULL,
  `transfer_principal_name` varchar(255) DEFAULT NULL,
  `cancellation_admitted_in` varchar(255) DEFAULT NULL,
  `cancellation_date` date DEFAULT NULL,
  `cancellation_principal_name` varchar(255) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `skill_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transaction_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `module` varchar(100) NOT NULL,
  `target_id` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transfer_requests` (
  `id` int(11) NOT NULL,
  `learner_id` varchar(12) NOT NULL,
  `from_school_id` int(11) NOT NULL,
  `to_school_id` int(11) DEFAULT NULL,
  `external_destination` varchar(255) DEFAULT NULL,
  `external_destination_details` text DEFAULT NULL,
  `requested_by` int(11) NOT NULL,
  `request_direction` enum('outbound','inbound') NOT NULL DEFAULT 'outbound',
  `transfer_type` enum('internal','external') NOT NULL DEFAULT 'internal',
  `requested_at` timestamp NULL DEFAULT current_timestamp(),
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','cancelled','rejected') DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `effective_sy` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transfer_request_documents` (
  `id` int(11) NOT NULL,
  `transfer_request_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(255) DEFAULT NULL,
  `username` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('superadmin','admin_office','school_coordinator','teacher') NOT NULL DEFAULT 'teacher',
  `school_id` int(11) DEFAULT NULL,
  `google_2fa_secret` varchar(255) DEFAULT NULL,
  `google_2fa_enabled` tinyint(1) DEFAULT 0,
  `data_privacy_acknowledged` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `assessments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lrn` (`lrn`),
  ADD KEY `enrollment_id` (`enrollment_id`),
  ADD KEY `created_by` (`created_by`);

ALTER TABLE `behavior_intervention_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_bir_quarter` (`lrn`,`school_year`,`quarter`),
  ADD KEY `school_id` (`school_id`),
  ADD KEY `created_by` (`created_by`);

ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `uploaded_by` (`uploaded_by`),
  ADD KEY `idx_lrn` (`lrn`),
  ADD KEY `idx_assessment` (`assessment_id`);

ALTER TABLE `enrollments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lrn` (`lrn`),
  ADD KEY `school_id` (`school_id`),
  ADD KEY `idx_enrollments_active_scope` (`lrn`,`school_id`,`is_deleted`,`is_historical`);

ALTER TABLE `enrollment_difficulties`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_enrollment_difficulty` (`enrollment_id`,`difficulty_key`),
  ADD KEY `idx_enrollment_difficulties_enrollment` (`enrollment_id`);

ALTER TABLE `enrollment_disabilities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_enrollment_disabilities_enrollment` (`enrollment_id`);

ALTER TABLE `guardians`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `ilp_goals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ilp_id` (`ilp_id`);

ALTER TABLE `ilp_team_members`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ilp_id` (`ilp_id`);

ALTER TABLE `learners`
  ADD PRIMARY KEY (`lrn`),
  ADD KEY `guardian_id` (`guardian_id`);

ALTER TABLE `learner_guardians`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lrn` (`lrn`);

ALTER TABLE `learner_iep`
  ADD PRIMARY KEY (`id`),
  ADD KEY `learner_id` (`learner_id`),
  ADD KEY `enrollment_id` (`enrollment_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `updated_by` (`updated_by`);

ALTER TABLE `learner_iep_annex6`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_learner_iep_annex6_sy` (`learner_id`,`school_year`),
  ADD KEY `enrollment_id` (`enrollment_id`),
  ADD KEY `created_by` (`created_by`);

ALTER TABLE `learner_iep_annex6_rows`
  ADD PRIMARY KEY (`id`),
  ADD KEY `iep_id` (`iep_id`);

ALTER TABLE `learner_iep_goals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `iep_id` (`iep_id`);

ALTER TABLE `learner_ilp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_learner_ilp_sy_doc` (`learner_id`,`school_year`,`document_type`),
  ADD KEY `learner_id` (`learner_id`),
  ADD KEY `enrollment_id` (`enrollment_id`),
  ADD KEY `adviser_id` (`adviser_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `updated_by` (`updated_by`);

ALTER TABLE `learner_ilp_assessments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ilp_id` (`ilp_id`);

ALTER TABLE `learner_ilp_therapy_services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ilp_therapy_services_ilp` (`ilp_id`),
  ADD KEY `idx_ilp_therapy_services_sort` (`ilp_id`,`sort_order`);

ALTER TABLE `learner_indicator_ratings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `learner_id` (`learner_id`),
  ADD KEY `enrollment_id` (`enrollment_id`),
  ADD KEY `updated_by` (`updated_by`);

ALTER TABLE `learner_itp`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_learner_itp_sy` (`learner_id`,`school_year`),
  ADD KEY `enrollment_id` (`enrollment_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `updated_by` (`updated_by`),
  ADD KEY `idx_learner_itp_learner` (`learner_id`),
  ADD KEY `idx_learner_itp_sy` (`school_year`);

ALTER TABLE `learner_itp_goal_rows`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_learner_itp_goal_itp` (`itp_id`),
  ADD KEY `idx_learner_itp_goal_sort` (`itp_id`,`sort_order`);

ALTER TABLE `learner_itp_plans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_learner_itp_plans_itp` (`itp_id`);

ALTER TABLE `learner_itp_team_members`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_learner_itp_team_itp` (`itp_id`),
  ADD KEY `idx_learner_itp_team_sort` (`itp_id`,`sort_order`);

ALTER TABLE `learner_skill_ratings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_assessment` (`assessment_id`),
  ADD KEY `idx_lrn` (`lrn`),
  ADD KEY `idx_category` (`skill_category_id`);

ALTER TABLE `learner_teachers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_teacher_learner` (`teacher_id`,`lrn`),
  ADD KEY `lrn` (`lrn`),
  ADD KEY `assigned_by` (`assigned_by`);

ALTER TABLE `learner_transfers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `learner_id` (`learner_id`),
  ADD KEY `from_school_id` (`from_school_id`),
  ADD KEY `to_school_id` (`to_school_id`),
  ADD KEY `transfer_request_id` (`transfer_request_id`);

ALTER TABLE `parental_consents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lrn` (`lrn`),
  ADD KEY `recorded_by` (`recorded_by`);

ALTER TABLE `schools`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `school_principals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `recorded_by` (`recorded_by`),
  ADD KEY `idx_school_id` (`school_id`),
  ADD KEY `idx_effective` (`school_id`,`effective_from`,`effective_until`);

ALTER TABLE `sf9_attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sf9_attendance_month` (`report_card_id`,`month_order`);

ALTER TABLE `sf9_quarter_notes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sf9_quarter_note` (`report_card_id`,`quarter`);

ALTER TABLE `sf9_report_cards`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sf9_report_card_lrn_sy` (`lrn`,`school_year`),
  ADD KEY `updated_by` (`updated_by`),
  ADD KEY `idx_sf9_report_card_lrn` (`lrn`),
  ADD KEY `idx_sf9_report_card_sy` (`school_year`);

ALTER TABLE `skill_categories`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `transaction_logs`
  ADD PRIMARY KEY (`log_id`);

ALTER TABLE `transfer_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `learner_id` (`learner_id`),
  ADD KEY `from_school_id` (`from_school_id`),
  ADD KEY `to_school_id` (`to_school_id`),
  ADD KEY `requested_by` (`requested_by`),
  ADD KEY `reviewed_by` (`reviewed_by`),
  ADD KEY `idx_transfer_requests_review` (`status`,`transfer_type`,`to_school_id`,`requested_at`),
  ADD KEY `idx_transfer_requests_sent` (`from_school_id`,`status`,`requested_at`);

ALTER TABLE `transfer_request_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_transfer_document` (`transfer_request_id`,`document_id`),
  ADD KEY `idx_transfer_request_documents_request` (`transfer_request_id`),
  ADD KEY `idx_transfer_request_documents_document` (`document_id`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `school_id` (`school_id`);

ALTER TABLE `assessments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `behavior_intervention_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `enrollment_difficulties`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `enrollment_disabilities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `guardians`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `ilp_goals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `ilp_team_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `learner_guardians`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `learner_iep`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `learner_iep_annex6`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `learner_iep_annex6_rows`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `learner_iep_goals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `learner_ilp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `learner_ilp_assessments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `learner_ilp_therapy_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `learner_indicator_ratings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `learner_itp`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `learner_itp_goal_rows`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `learner_itp_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `learner_itp_team_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `learner_skill_ratings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `learner_teachers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `learner_transfers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `parental_consents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `schools`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `school_principals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `sf9_attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `sf9_quarter_notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `sf9_report_cards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `skill_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `transaction_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `transfer_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `transfer_request_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `assessments`
  ADD CONSTRAINT `assessments_ibfk_1` FOREIGN KEY (`lrn`) REFERENCES `learners` (`lrn`) ON DELETE CASCADE,
  ADD CONSTRAINT `assessments_ibfk_2` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `assessments_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `behavior_intervention_reports`
  ADD CONSTRAINT `behavior_intervention_reports_ibfk_1` FOREIGN KEY (`lrn`) REFERENCES `learners` (`lrn`) ON DELETE CASCADE,
  ADD CONSTRAINT `behavior_intervention_reports_ibfk_2` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`),
  ADD CONSTRAINT `behavior_intervention_reports_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

ALTER TABLE `documents`
  ADD CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`lrn`) REFERENCES `learners` (`lrn`) ON DELETE CASCADE,
  ADD CONSTRAINT `documents_ibfk_2` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `documents_ibfk_3` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `enrollments`
  ADD CONSTRAINT `enrollments_ibfk_1` FOREIGN KEY (`lrn`) REFERENCES `learners` (`lrn`) ON DELETE CASCADE,
  ADD CONSTRAINT `enrollments_ibfk_2` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`);

ALTER TABLE `enrollment_difficulties`
  ADD CONSTRAINT `enrollment_difficulties_ibfk_1` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE CASCADE;

ALTER TABLE `enrollment_disabilities`
  ADD CONSTRAINT `enrollment_disabilities_ibfk_1` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE CASCADE;

ALTER TABLE `ilp_goals`
  ADD CONSTRAINT `ilp_goals_ibfk_1` FOREIGN KEY (`ilp_id`) REFERENCES `learner_ilp` (`id`) ON DELETE CASCADE;

ALTER TABLE `ilp_team_members`
  ADD CONSTRAINT `ilp_team_members_ibfk_1` FOREIGN KEY (`ilp_id`) REFERENCES `learner_ilp` (`id`) ON DELETE CASCADE;

ALTER TABLE `learners`
  ADD CONSTRAINT `learners_ibfk_1` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`);

ALTER TABLE `learner_guardians`
  ADD CONSTRAINT `learner_guardians_ibfk_1` FOREIGN KEY (`lrn`) REFERENCES `learners` (`lrn`) ON DELETE CASCADE;

ALTER TABLE `learner_iep`
  ADD CONSTRAINT `learner_iep_ibfk_1` FOREIGN KEY (`learner_id`) REFERENCES `learners` (`lrn`) ON DELETE CASCADE,
  ADD CONSTRAINT `learner_iep_ibfk_2` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `learner_iep_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `learner_iep_ibfk_4` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `learner_iep_annex6`
  ADD CONSTRAINT `learner_iep_annex6_ibfk_1` FOREIGN KEY (`learner_id`) REFERENCES `learners` (`lrn`) ON DELETE CASCADE,
  ADD CONSTRAINT `learner_iep_annex6_ibfk_2` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `learner_iep_annex6_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `learner_iep_annex6_rows`
  ADD CONSTRAINT `learner_iep_annex6_rows_ibfk_1` FOREIGN KEY (`iep_id`) REFERENCES `learner_iep_annex6` (`id`) ON DELETE CASCADE;

ALTER TABLE `learner_iep_goals`
  ADD CONSTRAINT `learner_iep_goals_ibfk_1` FOREIGN KEY (`iep_id`) REFERENCES `learner_iep` (`id`) ON DELETE CASCADE;

ALTER TABLE `learner_ilp`
  ADD CONSTRAINT `learner_ilp_ibfk_1` FOREIGN KEY (`learner_id`) REFERENCES `learners` (`lrn`) ON DELETE CASCADE,
  ADD CONSTRAINT `learner_ilp_ibfk_2` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `learner_ilp_ibfk_3` FOREIGN KEY (`adviser_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `learner_ilp_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `learner_ilp_ibfk_5` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `learner_ilp_assessments`
  ADD CONSTRAINT `learner_ilp_assessments_ibfk_1` FOREIGN KEY (`ilp_id`) REFERENCES `learner_ilp` (`id`) ON DELETE CASCADE;

ALTER TABLE `learner_ilp_therapy_services`
  ADD CONSTRAINT `learner_ilp_therapy_services_ibfk_1` FOREIGN KEY (`ilp_id`) REFERENCES `learner_ilp` (`id`) ON DELETE CASCADE;

ALTER TABLE `learner_indicator_ratings`
  ADD CONSTRAINT `learner_indicator_ratings_ibfk_1` FOREIGN KEY (`learner_id`) REFERENCES `learners` (`lrn`) ON DELETE CASCADE,
  ADD CONSTRAINT `learner_indicator_ratings_ibfk_2` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `learner_indicator_ratings_ibfk_3` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `learner_itp`
  ADD CONSTRAINT `learner_itp_ibfk_1` FOREIGN KEY (`learner_id`) REFERENCES `learners` (`lrn`) ON DELETE CASCADE,
  ADD CONSTRAINT `learner_itp_ibfk_2` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `learner_itp_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `learner_itp_ibfk_4` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `learner_itp_goal_rows`
  ADD CONSTRAINT `learner_itp_goal_rows_ibfk_1` FOREIGN KEY (`itp_id`) REFERENCES `learner_itp` (`id`) ON DELETE CASCADE;

ALTER TABLE `learner_itp_plans`
  ADD CONSTRAINT `learner_itp_plans_ibfk_1` FOREIGN KEY (`itp_id`) REFERENCES `learner_itp` (`id`) ON DELETE CASCADE;

ALTER TABLE `learner_itp_team_members`
  ADD CONSTRAINT `learner_itp_team_members_ibfk_1` FOREIGN KEY (`itp_id`) REFERENCES `learner_itp` (`id`) ON DELETE CASCADE;

ALTER TABLE `learner_skill_ratings`
  ADD CONSTRAINT `learner_skill_ratings_ibfk_1` FOREIGN KEY (`lrn`) REFERENCES `learners` (`lrn`) ON DELETE CASCADE,
  ADD CONSTRAINT `learner_skill_ratings_ibfk_2` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `learner_skill_ratings_ibfk_3` FOREIGN KEY (`skill_category_id`) REFERENCES `skill_categories` (`id`) ON DELETE CASCADE;

ALTER TABLE `learner_teachers`
  ADD CONSTRAINT `learner_teachers_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `learner_teachers_ibfk_2` FOREIGN KEY (`lrn`) REFERENCES `learners` (`lrn`) ON DELETE CASCADE,
  ADD CONSTRAINT `learner_teachers_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `learner_transfers`
  ADD CONSTRAINT `learner_transfers_ibfk_1` FOREIGN KEY (`learner_id`) REFERENCES `learners` (`lrn`) ON DELETE CASCADE,
  ADD CONSTRAINT `learner_transfers_ibfk_2` FOREIGN KEY (`from_school_id`) REFERENCES `schools` (`id`),
  ADD CONSTRAINT `learner_transfers_ibfk_3` FOREIGN KEY (`to_school_id`) REFERENCES `schools` (`id`),
  ADD CONSTRAINT `learner_transfers_ibfk_4` FOREIGN KEY (`transfer_request_id`) REFERENCES `transfer_requests` (`id`) ON DELETE SET NULL;

ALTER TABLE `parental_consents`
  ADD CONSTRAINT `parental_consents_ibfk_1` FOREIGN KEY (`lrn`) REFERENCES `learners` (`lrn`) ON DELETE CASCADE,
  ADD CONSTRAINT `parental_consents_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`);

ALTER TABLE `school_principals`
  ADD CONSTRAINT `school_principals_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `school_principals_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `sf9_report_cards`
  ADD CONSTRAINT `sf9_report_cards_ibfk_1` FOREIGN KEY (`lrn`) REFERENCES `learners` (`lrn`) ON DELETE CASCADE,
  ADD CONSTRAINT `sf9_report_cards_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `transfer_requests`
  ADD CONSTRAINT `transfer_requests_ibfk_1` FOREIGN KEY (`learner_id`) REFERENCES `learners` (`lrn`) ON DELETE CASCADE,
  ADD CONSTRAINT `transfer_requests_ibfk_2` FOREIGN KEY (`from_school_id`) REFERENCES `schools` (`id`),
  ADD CONSTRAINT `transfer_requests_ibfk_3` FOREIGN KEY (`to_school_id`) REFERENCES `schools` (`id`),
  ADD CONSTRAINT `transfer_requests_ibfk_4` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transfer_requests_ibfk_5` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `transfer_request_documents`
  ADD CONSTRAINT `transfer_request_documents_ibfk_1` FOREIGN KEY (`transfer_request_id`) REFERENCES `transfer_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transfer_request_documents_ibfk_2` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE;

ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE SET NULL;




-- Additional schema-only definitions required by retained application routes.
CREATE TABLE `announcements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `type` enum('info','warning','urgent') DEFAULT 'info',
  `is_active` tinyint(1) DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `assessment_checklist_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `learning_area` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subsection_label` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `group_header` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_number` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learner_assessment_checklist` (
  `id` int NOT NULL AUTO_INCREMENT,
  `learner_id` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` int NOT NULL,
  `pre_rating` enum('E','NYE','NO') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `post_rating` enum('E','NYE','NO') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rating` enum('E','NYE','NO') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_observed` date DEFAULT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_learner_item` (`learner_id`,`item_id`),
  KEY `idx_learner_id` (`learner_id`),
  KEY `idx_item_id` (`item_id`),
  CONSTRAINT `learner_assessment_checklist_ibfk_1` FOREIGN KEY (`learner_id`) REFERENCES `learners` (`lrn`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `learner_assessment_checklist_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `assessment_checklist_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `learner_assessment_dates` (
  `learner_id` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pre_date` date DEFAULT NULL,
  `post_date` date DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`learner_id`),
  CONSTRAINT `learner_assessment_dates_ibfk_1` FOREIGN KEY (`learner_id`) REFERENCES `learners` (`lrn`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
