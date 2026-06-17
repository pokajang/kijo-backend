# Second Wave Knowledge Migration

This document contains the second wave of Knowledge Hub article drafts to migrate from frontend/manual authoring into backend seed or migration data. The topics below are based on the current repo behavior, route definitions, service validations, and explorer findings from the salary, vendor, HR, admin, commercial, CRM, and Knowledge modules.

Use these entries as migration-ready source material. Each article follows the same shape as earlier seeded articles: title, slug, summary, category, tags, related route, and a `body_html` block using headings, ordered steps, lists, and practical edge-case notes.

## Migration Hardening Notes

- Source file location: `docs/work-notes/SECOND_WAVE_KNOWLEDGE_MIGRATION.md`.
- Seed each article as a published SYSTEM-managed article unless intentionally drafting it. Include `contributor_note`, `status => 'published'`, `published_at => $article->published_at ?? $now`, `created_by_staff_id => null`, `created_by_name_code => 'SYSTEM'`, `updated_by_staff_id => null`, `updated_by_name_code => 'SYSTEM'`, `created_at`, and `updated_at`.
- Insert a `knowledge_article_edit_logs` row when the edit-log table exists, matching the first-wave seed pattern.
- Use lowercase kebab-case seed tags, keeping common acronyms lowercased, for example `other-claim`, `payment-queue`, `system-admin`, `pdf`, `crm`, `roi`, `csrf`, `smtp`, `hr`, and `laravel`.
- These articles are additive deep dives, not replacements for first-wave guides. Keep slugs unique and avoid retitling existing SYSTEM-managed articles unless the migration intentionally updates them.
- Several System Admin articles intentionally share `/system-admin/dashboard`. Keep titles, summaries, and tags sharply distinct because route boosting can otherwise make dashboard articles compete for vague System Admin questions.
- `/knowledge` routes are intentionally excluded from assistant inline route links. Article 16 can keep `/knowledge` for article browsing, but do not expect the assistant to produce it as a clickable internal route candidate.

## Article 01 - How to Apply Salary and Submit Other Claims

- Title: How to Apply Salary and Submit Other Claims
- Slug: `how-to-apply-salary-and-submit-other-claims`
- Category: `Leave & HR`
- Related route: `/my/salary/apply`
- Tags: `salary`, `claims`, `other-claim`, `medical`, `mileage`, `allowance`, `draft`
- Summary: Learn how to create a salary application, save drafts, submit other claims, attach proof where required, and understand which statuses can still be edited.
- Evidence: `frontend/src/routes.js`, `frontend/src/features/salary/self/SalaryWorkspace.js`, `backend-laravel/routes/api.php`, `backend-laravel/app/Services/Salary/SalaryService.php`, `backend-laravel/app/Services/Salary/OtherClaimService.php`

```html
<h3>When to use salary application and other claims</h3>
<p>Use the salary workspace when you need to submit a monthly salary application or a separate other claim such as allowance, expense, medical, or mileage. Salary applications and other claims are related, but they are submitted from different pages and keep separate records.</p>

<h3>Apply for salary</h3>
<ol>
    <li>Open <strong>My Salary</strong> and go to <strong>Apply Salary</strong>.</li>
    <li>Select the salary month or payment period.</li>
    <li>Review the salary summary before submitting. The system needs a valid salary profile and basic salary before the salary application can be submitted.</li>
    <li>If an adjustment is needed, use <strong>Add Adjustment</strong>, choose <strong>Salary Adjustment</strong>, then enter the date, description, and amount.</li>
    <li>Save the adjustment line, review the total, then submit the salary application.</li>
</ol>

<h3>Submit an other claim</h3>
<ol>
    <li>Open <strong>My Salary</strong> and go to <strong>Apply Other Claim</strong>.</li>
    <li>Use <strong>Add Claim</strong> and choose the claim type: <strong>Non-Recurring Allowance</strong>, <strong>Expense</strong>, <strong>Medical</strong>, or <strong>Mileage</strong>.</li>
    <li>Fill in the claim date and details. For mileage, enter the travel information and one-way kilometer value; the form calculates the return trip value.</li>
    <li>Attach proof where required. Expense and Medical require supporting attachment in both the UI and backend; Mileage forbids attachments.</li>
    <li>Submit once at least one valid claim line is ready.</li>
</ol>

<h3>Attachment and medical claim rules</h3>
<ul>
    <li>Salary applications only allow salary adjustment or allowance-style lines and reject claim attachments. Use Other Claim for attachment-backed reimbursements.</li>
    <li>Other Claim attachments must be PDF, JPG, JPEG, or PNG files up to 5 MB.</li>
    <li>Medical claims are checked against the yearly medical claim limit, including prior medical usage for that year.</li>
</ul>

<h3>Drafts and records</h3>
<p>Salary drafts and other claim drafts are stored separately. Use <strong>Salary Records</strong> to review submitted salary applications, and <strong>Other Claim Records</strong> to review submitted claims. Detail pages are available for both salary and other claim records.</p>

<h3>What can still be changed</h3>
<ul>
    <li><strong>Draft</strong>, <strong>Submitted</strong>, <strong>Prepared</strong>, and <strong>Rejected</strong> records are staff-mutable statuses.</li>
    <li><strong>Checked</strong> and <strong>Approved</strong> records are already in review flow and may require amendment handling and a reason.</li>
    <li><strong>Paid</strong> records cannot be changed through the normal application form.</li>
    <li>A duplicate active salary record for the same employee and period can block submission.</li>
</ul>

<p><strong>Tip:</strong> Keep receipts and claim proof ready before submitting. File attachments are part of the claim record and help approvers complete the financial review without returning or rejecting the item for missing support.</p>
```

## Article 02 - How Salary and Other Claim Approvals Work

- Title: How Salary and Other Claim Approvals Work
- Slug: `how-salary-and-other-claim-approvals-work`
- Category: `Leave & HR`
- Related route: `/financial/salary-records`
- Tags: `salary`, `other-claim`, `approval`, `workflow`, `check`, `approve`, `reject`
- Summary: Understand how salary and other claim records move through financial checking, approval, rejection, and payment readiness.
- Evidence: `frontend/src/routes.js`, `backend-laravel/routes/api.php`, `backend-laravel/app/Services/Salary/SalaryService.php`, `backend-laravel/app/Services/Salary/OtherClaimService.php`, `backend-laravel/app/Services/Workflows/WorkflowService.php`

```html
<h3>Approval pages</h3>
<p>Salary and other claim approvals are handled from the financial records pages, not from the staff application form. Salary approvals are reviewed at <strong>Financial Salary Records</strong>, while other claim approvals are reviewed at <strong>Financial Other Claim Records</strong>.</p>

<h3>Review the workflow status</h3>
<ol>
    <li>Open the financial salary or other claim records page.</li>
    <li>Find the record that needs action.</li>
    <li>Review the record amount, supporting details, attachments, and the <strong>Workflow</strong> column.</li>
    <li>Use the available workflow action button, such as <strong>Check</strong>, <strong>Approve</strong>, or <strong>Reject</strong>.</li>
    <li>Enter remarks in the modal and submit the action.</li>
</ol>

<h3>Normal approval flow</h3>
<ul>
    <li><strong>Submitted</strong> or <strong>Prepared</strong> records wait for the check step.</li>
    <li><strong>Checked</strong> records wait for approval.</li>
    <li><strong>Approved</strong> records can move to payment queue handling.</li>
    <li><strong>Rejected</strong> records cannot be actioned further in the approval queue.</li>
    <li><strong>Cancelled</strong> records are no longer available for financial action.</li>
</ul>

<h3>Separation of duties</h3>
<p>The workflow service prevents common approval conflicts. Salary makers cannot check or approve their own salary record. Other claim approvals are stricter for normal users, but System Admin can self-action other claims and can bypass the checker or approver separation rule in that path. Approval still requires the prior check step unless the workflow configuration intentionally skips it. Users must either be assigned to the current workflow step or match the fallback role rules configured for that template.</p>

<h3>Exports and supporting PDFs</h3>
<p>Approved salary records support salary-related PDF exports such as claim PDF or payslip PDF where available. Use these PDFs after the record has reached the correct review status and the details are ready to share or file.</p>

<p><strong>Important:</strong> Draft records are not ready for financial approval. Ask the staff member to submit the application first if the record is still in draft state.</p>
```

## Article 03 - How to Use the Salary Payment Queue

- Title: How to Use the Salary Payment Queue
- Slug: `how-to-use-the-salary-payment-queue`
- Category: `Leave & HR`
- Related route: `/financial/payment-queue`
- Tags: `salary`, `payment-queue`, `paid`, `undo-paid`, `bulk-payment`, `finance`
- Summary: Learn how approved salary and other claim records appear in the payment queue, how finance marks rows paid, and when rows are blocked or restricted.
- Evidence: `frontend/src/routes.js`, `frontend/src/components/salary/PaymentQueueRecords.js`, `backend-laravel/routes/api.php`, `backend-laravel/app/Services/Salary/PaymentQueueService.php`

```html
<h3>What the payment queue does</h3>
<p>The salary payment queue groups approved salary applications and approved other claim applications by staff and payment period. It helps the salary payment roles, including HR, Manager, Finance, Account, and Bank, confirm what is ready to pay, mark payments as paid, and undo payment marking when a correction is needed.</p>

<h3>Open the queue</h3>
<ol>
    <li>Open <strong>Financial Payment Queue</strong> for finance handling, or <strong>My Salary Payment Queue</strong> for self-service visibility.</li>
    <li>Search or filter the queue by status.</li>
    <li>Open a queue row to view the staff, period, included salary records, included other claims, and payment readiness.</li>
    <li>If the row is eligible, use <strong>Mark Paid</strong>.</li>
    <li>Confirm the required staff and payment period. Payment date is optional and defaults to today; reference, method, and remarks are optional metadata.</li>
</ol>

<h3>Bulk payment actions</h3>
<p>When multiple rows are ready, payment roles can use selection tools such as <strong>Select Eligible</strong>, <strong>Mark Paid Selected</strong>, or <strong>Undo Paid Selected</strong>. Bulk actions still apply the same rules as a single-row action.</p>

<h3>Queue statuses</h3>
<ul>
    <li><strong>Pending Payment</strong> means the row is approved and ready for payment handling.</li>
    <li><strong>Paid</strong> means a payment run has already marked the row as paid.</li>
    <li><strong>Blocked</strong> means the row cannot be paid until the issue is fixed.</li>
    <li><strong>Restricted</strong> means the viewer is not allowed to see the row values.</li>
</ul>

<h3>Why a row can be blocked</h3>
<ul>
    <li>There is a duplicate approved salary record for the same staff and period.</li>
    <li>The total is negative or otherwise not payable.</li>
    <li>The payment tables or required payment data are unavailable.</li>
    <li>The current user is trying to mark their own payment row as paid.</li>
</ul>

<p>Rows with invalid or incomplete workflow completion are normally skipped from the queue listing. If the row changes between viewing and marking paid, the mark-paid action can fail and ask the user to refresh.</p>

<h3>Undo paid</h3>
<p>Use <strong>Undo Paid</strong> only when a paid row was marked incorrectly. A reason is required. The service voids the payment run and moves the included salary or other claim records back to approved status if they are still paid.</p>

<p><strong>Tip:</strong> If the queue says the row changed, refresh before marking paid. This avoids paying a stale row after another user or workflow action changed the underlying records.</p>
```

## Article 04 - How to Configure Approval Workflows

- Title: How to Configure Approval Workflows
- Slug: `how-to-configure-approval-workflows`
- Category: `System`
- Related route: `/workflows/salary-application`
- Tags: `workflow`, `approval`, `salary`, `vendor-payment`, `leave`, `negotiation`, `manager`
- Summary: Learn how managers and system admins configure salary, vendor payment, leave, and negotiation approval templates.
- Evidence: `frontend/src/routes.js`, `frontend/src/views/internal-operations/workflows/WorkflowsPage.jsx`, `backend-laravel/routes/api.php`, `backend-laravel/app/Services/Workflows/WorkflowService.php`, `backend-laravel/app/Services/Vendors/VendorPaymentWorkflowService.php`

```html
<h3>Who can manage workflows</h3>
<p>Workflow setup is editable by <strong>Manager</strong> and <strong>System Admin</strong> roles. Backend read endpoints are broadly available to authenticated users where routes permit access, while saving templates is restricted to Manager and System Admin. The frontend workflow route is limited to finance and management-style roles.</p>

<h3>Open workflow settings</h3>
<ol>
    <li>Open <strong>Workflows</strong>. The default workflow page redirects to the salary application template.</li>
    <li>Use the workflow tabs to switch between <strong>Salary</strong>, <strong>Vendor Payment</strong>, <strong>Leave Application</strong>, and <strong>Negotiation</strong>.</li>
    <li>Review the configured recipients and any setup warnings.</li>
    <li>Make changes only for the workflow template you intend to update.</li>
    <li>Save the settings and test with a new request if the workflow change affects active operations.</li>
</ol>

<h3>Salary workflow</h3>
<p>The salary template uses <strong>Check</strong> and <strong>Approve</strong> steps. You can assign named staff recipients for each step. If no named recipients are configured, fallback roles apply. Salary check fallback roles include Finance, Account, HR, Manager, and System Admin. Salary approval fallback roles include Manager, Finance, and System Admin.</p>

<h3>Vendor payment workflow</h3>
<p>The vendor payment template supports configurable review and approval levels plus a finance step. Review and approval can be enabled or disabled, and each enabled stage supports 1 to 5 levels. The finance step uses configured recipients where set, otherwise it falls back to Finance, Account, Bank, Manager, and System Admin roles.</p>

<h3>Leave and negotiation workflows</h3>
<ul>
    <li><strong>Leave Application</strong> controls who recommends and approves leave requests.</li>
    <li><strong>Negotiation</strong> controls who approves quote price exception or negotiation requests.</li>
</ul>

<h3>Missing setup warnings</h3>
<p>The setup status can report missing recipient coverage when a step has no named recipients. Check the template before assuming a request is stuck. Some templates still have fallback role behavior, but named recipients make the workflow clearer and easier to audit.</p>

<p><strong>Important:</strong> Workflow changes affect future routing and active workflow actions. Keep recipient lists limited to staff who should genuinely check, approve, or finance that record type.</p>
```

## Article 05 - How Vendor Payment Requests and Approvals Work

- Title: How Vendor Payment Requests and Approvals Work
- Slug: `how-vendor-payment-requests-and-approvals-work`
- Category: `Vendors`
- Related route: `/vendor/payment-records`
- Tags: `vendor`, `payment`, `approval`, `mark-paid`, `project`, `office`, `workflow`
- Summary: Learn how vendor payment requests are created, checked, approved, returned, rejected, marked paid, and reviewed in the vendor ledger.
- Evidence: `frontend/src/routes.js`, `frontend/src/views/vendor/pay/PayVendor.js`, `frontend/src/views/vendor/payment-records/PaymentRecords.js`, `frontend/src/views/vendor/paid/PaidByVendorPage.jsx`, `backend-laravel/routes/api.php`, `backend-laravel/app/Services/Vendors/VendorPaymentService.php`, `backend-laravel/app/Services/Vendors/VendorPaymentWorkflowService.php`

```html
<h3>Create a vendor payment request</h3>
<ol>
    <li>Open <strong>Request Vendor Payment</strong> from the vendor payment area.</li>
    <li>Select the payment context: <strong>Project</strong>, <strong>Office</strong>, or <strong>Other</strong>.</li>
    <li>For a project payment, select an active project and then select a vendor assigned to that project.</li>
    <li>For office or other payments, select an active vendor from the vendor list.</li>
    <li>Choose the payment type, enter the amount, choose the payment method, upload the invoice or receipt file, add remarks, and submit.</li>
</ol>

<h3>Required information</h3>
<ul>
    <li>The vendor must be active.</li>
    <li>The amount must be greater than zero.</li>
    <li>Project payments require a project linked to the requester and a vendor assigned to that project.</li>
    <li>The current React form requires an uploaded receipt or invoice, but the backend request currently permits the receipt field to be empty.</li>
    <li><strong>Other</strong> context requires remarks.</li>
    <li>Accepted uploaded receipt or invoice files include PDF, JPG, JPEG, and PNG in the current form.</li>
</ul>

<h3>Approval queue actions</h3>
<p>Approvers use <strong>Vendor Payment Records</strong> to act on payment requests. Pending items can be checked, returned, or rejected. Checked items can be approved, returned, or rejected. Approved items can be marked paid by finance-capable roles.</p>

<h3>Status flow</h3>
<ul>
    <li><strong>Pending</strong> is waiting for review or check.</li>
    <li><strong>Checked</strong> is waiting for approval.</li>
    <li><strong>Approved</strong> is ready for finance payment marking.</li>
    <li><strong>Returned</strong> sends the request back for correction.</li>
    <li><strong>Rejected</strong> stops the request.</li>
    <li><strong>Paid</strong> means finance has marked payment completion.</li>
</ul>

<p>The initial status depends on workflow setup. If review is disabled, a new request can start as <strong>Checked</strong>. If both review and approval are disabled, it can start as <strong>Approved</strong>.</p>

<h3>Separation of duties</h3>
<p>The requester cannot check or approve their own request. The checker or reviewer and the approver must be different people. Finance payment marking is allowed only after approval and only for roles allowed by the payment workflow and service rules.</p>

<h3>Vendor ledger</h3>
<p>Use the <strong>Vendor Ledger</strong> page to review paid vendors, paid records, and total paid values after payments are completed. The ledger is separate from the payment request queue.</p>
```

## Article 06 - How Handbook Publishing and Acknowledgements Work

- Title: How Handbook Publishing and Acknowledgements Work
- Slug: `how-handbook-publishing-and-acknowledgements-work`
- Category: `Leave & HR`
- Related route: `/handbook`
- Tags: `handbook`, `acknowledgement`, `signature`, `version`, `draft`, `publish`
- Summary: Learn how staff acknowledge the handbook, how managers publish handbook drafts, and why each new version requires a fresh acknowledgement.
- Evidence: `frontend/src/views/handbook/Handbook.js`, `frontend/src/views/handbook/api/handbookApi.js`, `backend-laravel/routes/api.php`, `backend-laravel/app/Services/Handbook/HandbookPublicationService.php`, `backend-laravel/app/Services/Handbook/HandbookSignatureService.php`

```html
<h3>Read and acknowledge the handbook</h3>
<ol>
    <li>Open <strong>Handbook</strong>.</li>
    <li>Read the current official handbook sections.</li>
    <li>Click <strong>Acknowledge &amp; Sign</strong> when you are ready to confirm.</li>
    <li>Enter your full name and IC number.</li>
    <li>Confirm the acknowledgement.</li>
</ol>

<h3>What acknowledgement records mean</h3>
<p>A handbook acknowledgement is tied to the current handbook version. When a new version is published or an older version is reactivated, staff must acknowledge that version again. Only one signature is allowed per staff member for the current version.</p>

<h3>Who can manage handbook content</h3>
<p><strong>System Admin</strong>, <strong>HR</strong>, and <strong>Manager</strong> roles can manage handbook drafts. They can edit sections, save draft section changes with a change summary, preview official versus draft content, publish the draft, discard the draft, view signatures, view change logs, and view version history.</p>

<h3>Publish a handbook draft</h3>
<ol>
    <li>Open the handbook as an authorized manager, HR, or system admin user.</li>
    <li>Edit the needed section and save it as a draft with a change summary.</li>
    <li>Review the draft preview before publishing.</li>
    <li>Use <strong>Publish Draft</strong> and provide the final publish summary.</li>
    <li>After publish, staff see the new official version and must acknowledge it.</li>
</ol>

<h3>Version and stale-page safeguards</h3>
<ul>
    <li>Signing is disabled if the server handbook cannot load.</li>
    <li>Signing is disabled if the page is stale and the handbook changed while it was open.</li>
    <li>Submitting a stale version ID is rejected with a conflict response.</li>
    <li>A handbook version cannot be reactivated while an active draft exists; publish or discard the draft first.</li>
    <li>The signature list intentionally does not expose IC numbers.</li>
</ul>

<p><strong>Tip:</strong> If you see a stale handbook warning, reload before signing or editing. This ensures your acknowledgement or draft update is tied to the correct handbook version.</p>
```

## Article 07 - How to Manage Leave Approvals and Entitlements

- Title: How to Manage Leave Approvals and Entitlements
- Slug: `how-to-manage-leave-approvals-and-entitlements`
- Category: `Leave & HR`
- Related route: `/staff/leaves`
- Tags: `leave`, `approval`, `entitlement`, `recommend`, `approve`, `cancel`, `revoke`
- Summary: Learn how leave records are recommended, approved, rejected, cancelled, and connected to staff leave entitlements.
- Evidence: `frontend/src/views/staff/leaves/*`, `backend-laravel/routes/api.php`, `backend-laravel/app/Services/Leaves/LeaveRequestService.php`, `backend-laravel/app/Services/Leaves/LeaveEntitlementService.php`, `backend-laravel/app/Http/Requests/Leave/LeaveActionRequest.php`

```html
<h3>Staff leave submission</h3>
<p>Staff submit leave from the self-service leave pages by selecting the leave type, reason, start and end dates, and half-day or full-day timing. The frontend calculates duration, and the backend validates the request again before saving.</p>

<h3>Approval flow</h3>
<ol>
    <li>Managers review pending unreviewed leave and either recommend or reject it.</li>
    <li>HR or System Admin reviews recommended leave and either approves or rejects it.</li>
    <li>Remarks are captured when actions are submitted.</li>
    <li>Approved leave updates entitlement usage for paid leave types.</li>
</ol>

<h3>Leave cancellation and revocation</h3>
<ul>
    <li>Staff can cancel their own pending or approved leave where allowed.</li>
    <li>HR and System Admin can revoke approved leave in the UI; backend cancellation is guarded by privileged-role rules.</li>
    <li>When approved paid leave is cancelled, the used entitlement days are reversed.</li>
</ul>

<h3>Entitlement rules</h3>
<p>Leave entitlements control paid leave balance. Paid leave types are checked against the entitlement year determined from the leave start date, not always the current calendar year. <strong>Unpaid</strong> and <strong>Others</strong> bypass paid entitlement checks.</p>

<h3>Manage leave entitlements</h3>
<ol>
    <li>Open the staff leave entitlement pages.</li>
    <li>Assign entitlement by staff, leave type, year, and number of days.</li>
    <li>Use the entitlement detail page to review assigned days, used days, and balance.</li>
    <li>Use year-specific or all-time views when checking leave usage history.</li>
</ol>

<h3>Common validation rules</h3>
<ul>
    <li>Same-day leave start time must be before end time.</li>
    <li>Approval requires the recommendation step first.</li>
    <li>Route and body leave IDs must match.</li>
    <li>Duplicate entitlement for the same staff, leave type, and year is rejected.</li>
    <li>Used entitlements cannot change staff, leave type, or year.</li>
    <li>Entitlement days cannot be reduced below already used days.</li>
    <li>Used entitlements cannot be deleted.</li>
</ul>

<p><strong>Tip:</strong> If approval fails because of entitlement balance, update or correct the entitlement first, then retry the leave action.</p>
```

## Article 08 - How to Use AI Assistant Governance

- Title: How to Use AI Assistant Governance
- Slug: `how-to-use-ai-assistant-governance`
- Category: `System`
- Related route: `/system-admin/dashboard`
- Tags: `ai-assistant`, `governance`, `feedback`, `cache`, `source-gaps`, `system-admin`
- Summary: Learn how system admins review assistant feedback, source gaps, cache entries, provider memory, blocked signatures, and assistant reliability metrics.
- Evidence: `frontend/src/views/system-admin/SectionAiAssistantGovernance.jsx`, `backend-laravel/routes/api.php`, `backend-laravel/app/Http/Controllers/Api/AdminAssistantGovernanceController.php`

```html
<h3>Open AI Assistant Governance</h3>
<ol>
    <li>Open <strong>System Admin Dashboard</strong>.</li>
    <li>Select the <strong>AI Assistant Governance</strong> tab.</li>
    <li>Review the summary tiles before drilling into individual records.</li>
    <li>Use filters such as date range, provider, confidence, answer mode, and rating for analytics and source-gap review. Feedback, cache, and provider-memory row loads use their own unfiltered endpoints.</li>
</ol>

<h3>Governance views</h3>
<ul>
    <li><strong>Feedback</strong> shows helpful and bad feedback, questions, answer excerpts, reasons, confidence, mode, and blocked indicators.</li>
    <li><strong>Cache</strong> shows static or live cache entries, normalized questions, answer excerpts, hit counts, refresh dates, expiry dates, and unblock actions where available.</li>
    <li><strong>Provider Memory</strong> shows provider-level learning or feedback data used by the assistant governance tools.</li>
    <li><strong>Source Gaps</strong> shows questions where the assistant lacked enough source material or confidence.</li>
</ul>

<h3>Source gap actions</h3>
<p>Source gaps can be updated with status, priority, and notes. Supported statuses include <strong>open</strong>, <strong>planned</strong>, <strong>resolved</strong>, and <strong>ignored</strong>. Priorities include <strong>low</strong>, <strong>medium</strong>, and <strong>high</strong>.</p>

<h3>Create follow-up work from a source gap</h3>
<ul>
    <li>Use provider backlog promotion when the gap should become provider follow-up work.</li>
    <li>Use knowledge draft creation when the answer needs a Knowledge Hub article. The system creates a draft article under the AI Source Gaps category and marks the gap planned.</li>
    <li>Use ignore or resolve when the gap does not need new content.</li>
</ul>

<h3>Blocked signatures and unavailable storage</h3>
<p>Blocked answer signatures can be unblocked from the governance page. If the required feedback, source-gap, or action tables are missing, some action endpoints can return unavailable responses while the read views still render empty dashboard rows.</p>

<p><strong>Important:</strong> This page is restricted to System Admin. Non-System Admin users receive an authorization error from the backend.</p>
```

## Article 09 - How to Manage Performance Appraisals and Final Appraisals

- Title: How to Manage Performance Appraisals and Final Appraisals
- Slug: `how-to-manage-performance-appraisals-and-final-appraisals`
- Category: `Leave & HR`
- Related route: `/staff/appraise`
- Tags: `appraisal`, `performance`, `feedback`, `final-appraisal`, `hr`, `manager`
- Summary: Learn how managers and HR users record appraisal feedback, create final appraisals, and understand appraisal access rules.
- Evidence: `frontend/src/views/staff/appraise/*`, `backend-laravel/routes/api.php`, `backend-laravel/app/Services/Appraisals/AppraisalBaseService.php`, `backend-laravel/app/Services/Appraisals/AppraisalRecordService.php`, `backend-laravel/app/Services/Appraisals/FinalAppraisalService.php`

```html
<h3>Who manages appraisals</h3>
<p>Appraisal management is intended for roles whose names contain HR, manager, admin, or super. Staff can use personal appraisal views for their own records, while appraisal managers can list, create, update, and delete appraisal records where allowed.</p>

<h3>Add appraisal feedback</h3>
<ol>
    <li>Open <strong>Staff Appraise</strong>.</li>
    <li>Choose <strong>Add Feedback</strong>.</li>
    <li>Select the staff member.</li>
    <li>Select the feedback type, such as <strong>Positive Observation</strong>, <strong>Outstanding Achievement</strong>, or <strong>Areas for Improvement</strong>.</li>
    <li>Enter the event date and feedback text.</li>
    <li>Submit the feedback record.</li>
</ol>

<h3>Create a final appraisal</h3>
<ol>
    <li>Open <strong>Final Appraisal</strong> from the appraisal area.</li>
    <li>Select the staff member and appraisal date.</li>
    <li>Review previous feedback records shown for that staff member.</li>
    <li>Enter the final appraisal ratings, supervisor comments, salary increment recommendation, and promotion recommendation.</li>
    <li>Submit the final appraisal.</li>
</ol>

<h3>Records list and detail pages</h3>
<p>The appraisal records page combines feedback appraisal records and final appraisal records in the frontend. The backend still stores and serves feedback appraisals and final appraisals through separate endpoints and services. Use search and filters to find staff, appraiser, type, or feedback. Records can be exported from the list view, and final appraisal records open their own final appraisal detail page.</p>

<h3>Important limits</h3>
<ul>
    <li>The personal endpoint only returns records for the logged-in staff member.</li>
    <li>Final appraisal staff cannot be changed on update. Create a new final appraisal if the staff member was selected incorrectly.</li>
    <li>Invalid staff filters or non-4-digit year filters are rejected by validation.</li>
</ul>

<p><strong>Tip:</strong> Add ongoing feedback during the year instead of waiting for final appraisal. The final appraisal form shows previous feedback so the final decision can be grounded in recorded events.</p>
```

## Article 10 - How to Create and Publish What's New Notices

- Title: How to Create and Publish What's New Notices
- Slug: `how-to-create-and-publish-whats-new-notices`
- Category: `System`
- Related route: `/system-admin/whats-new`
- Tags: `whats-new`, `notice`, `publish`, `announcement`, `system-admin`, `images`
- Summary: Learn how system admins create What's New notices, attach images, publish or unpublish notices, and how staff read and mark notices seen.
- Evidence: `frontend/src/views/system-admin/whats-new/*`, `frontend/src/components/WhatsNewNotifier.jsx`, `backend-laravel/routes/api.php`, `backend-laravel/app/Services/WhatsNew/WhatsNewReadService.php`, `backend-laravel/app/Services/WhatsNew/WhatsNewMutationService.php`, `backend-laravel/app/Services/WhatsNew/WhatsNewBaseService.php`

```html
<h3>What What's New notices do</h3>
<p>What's New notices announce product updates, process changes, and internal feature releases to authenticated users. Staff can view published notices from <strong>What's New</strong>, and unread published notices can appear in the notifier.</p>

<h3>Create a notice</h3>
<ol>
    <li>Open <strong>System Admin</strong> and go to <strong>What's New</strong>.</li>
    <li>Click <strong>Create Notice</strong>.</li>
    <li>Enter the title, summary, and rich body content.</li>
    <li>Add an optional action label and action path if the notice should lead users to a page.</li>
    <li>Attach images if useful, then add a description for each image.</li>
    <li>Choose whether to publish immediately.</li>
    <li>Save the notice.</li>
</ol>

<p>Each notice has a unique version. The backend can accept a provided version or generate one from the current date and sequence.</p>

<h3>Image and content rules</h3>
<ul>
    <li>Use JPG, PNG, or WebP images.</li>
    <li>Attach up to 3 images per notice.</li>
    <li>Each image can be up to 5 MB.</li>
    <li>Every image needs a description before saving.</li>
    <li>The notice needs meaningful content such as summary, body, items, or image content.</li>
    <li>Action paths must start with <code>/</code>.</li>
</ul>

<h3>Publish, unpublish, edit, and delete</h3>
<p>System Admin users can create, edit, publish, unpublish, and delete notices. Regular users only see notices that are published, have a published date, and are not future-dated.</p>

<h3>Read tracking</h3>
<p>When a user opens a published unread notice detail, the notice is marked read for that staff member. The system also supports marking all unread published notices as read.</p>

<h3>Draft recovery</h3>
<p>The create form keeps local draft content in browser storage. If an unsaved draft is recovered, reattach any images before publishing because file attachments are not restored from local storage.</p>

<p><strong>Important:</strong> Only System Admin can mutate notices. Non-admin users can read published notices but cannot create, edit, publish, unpublish, or delete them.</p>
```

## Article 11 - How to Use Monthly Dashboard Report Scheduling

- Title: How to Use Monthly Dashboard Report Scheduling
- Slug: `how-to-use-monthly-dashboard-report-scheduling`
- Category: `System`
- Related route: `/system-admin/dashboard`
- Tags: `dashboard-report`, `monthly-report`, `schedule`, `email`, `system-admin`, `pdf`
- Summary: Learn how system admins configure scheduled dashboard report emails, send a test report, and read send logs.
- Evidence: `frontend/src/views/system-admin/SectionMonthlyReportSchedulerTest.jsx`, `backend-laravel/routes/api.php`, `backend-laravel/app/Http/Controllers/Api/AdminMonthlyDashboardReportTestController.php`, `backend-laravel/app/Services/Stats/MonthlyDashboardReportService.php`, `backend-laravel/app/Console/Commands/GenerateMonthlyDashboardReport.php`, `backend-laravel/routes/console.php`

```html
<h3>What the monthly report scheduler sends</h3>
<p>The monthly dashboard report tool generates a year-to-date dashboard management report PDF and can email a signed public report link to configured recipients. The system admin page also supports manual test sends.</p>

<h3>Save the schedule</h3>
<ol>
    <li>Open <strong>System Admin Dashboard</strong>.</li>
    <li>Select the <strong>Monthly Report Test</strong> tab.</li>
    <li>Set whether the schedule is enabled.</li>
    <li>Enter the interval value and unit. Units can be days, weeks, or months.</li>
    <li>Choose the schedule start date.</li>
    <li>Choose the send time in 24-hour <code>HH:MM</code> format.</li>
    <li>Click <strong>Save Schedule</strong>.</li>
</ol>

<h3>Send a test report</h3>
<ol>
    <li>Enter one or more recipients. The UI label is singular, but the backend accepts comma-separated recipients and <code>Name &lt;email&gt;</code> entries.</li>
    <li>Click <strong>Generate and Send Report</strong>.</li>
    <li>The UI sends a forced test generation for the previous report month when no month is provided.</li>
    <li>Review the test log row for status, response message, public link, and expiry.</li>
</ol>

<h3>Schedule states</h3>
<ul>
    <li><strong>Next send</strong> shows the next scheduled run time when enabled.</li>
    <li><strong>Last status</strong> shows the latest scheduled attempt status.</li>
    <li>Test logs can show <strong>sending</strong>, <strong>sent</strong>, or <strong>failed</strong>.</li>
    <li>Public report links include an expiry timestamp.</li>
</ul>

<h3>Validation and skip cases</h3>
<ul>
    <li>Interval value must be between 1 and 365.</li>
    <li>Interval unit must be days, weeks, or months.</li>
    <li>Start date must be a valid date.</li>
    <li>Send time must be a valid 24-hour time.</li>
    <li>Invalid recipient emails return validation errors.</li>
    <li>The scheduled command can skip when locked, disabled, not due, missing the settings table, or no configured recipients exist.</li>
</ul>

<p><strong>Tip:</strong> Use the manual test send after changing the schedule or mail settings. It confirms report generation, email delivery, public link creation, and log recording in one place.</p>
```

## Article 12 - How to Use Mail Diagnostics

- Title: How to Use Mail Diagnostics
- Slug: `how-to-use-mail-diagnostics`
- Category: `System`
- Related route: `/system-admin/dashboard`
- Tags: `mail`, `email`, `smtp`, `diagnostics`, `quote-pdf`, `system-admin`
- Summary: Learn how system admins send default and quote PDF diagnostic emails, interpret blocked or failed results, and confirm sender configuration.
- Evidence: `frontend/src/views/system-admin/SectionMailDiagnostics.jsx`, `backend-laravel/routes/api.php`, `backend-laravel/app/Http/Controllers/Api/AdminMailDiagnosticsController.php`

```html
<h3>When to use mail diagnostics</h3>
<p>Use mail diagnostics when users report missing emails, quote PDF emails are not delivering, or SMTP settings were changed. The page sends controlled test emails and records the result without exposing mail passwords.</p>

<h3>Send a diagnostic email</h3>
<ol>
    <li>Open <strong>System Admin Dashboard</strong>.</li>
    <li>Select the <strong>Email Test</strong> tab.</li>
    <li>Enter the recipient email address.</li>
    <li>Click <strong>Send Default Email Test</strong> to test the default KIJO system sender.</li>
    <li>Click <strong>Send Quote PDF Email Test</strong> to test the quotation sender and PDF attachment pipeline.</li>
    <li>Review the log row for status, from address, response, and attachment name.</li>
</ol>

<h3>Expected senders</h3>
<ul>
    <li>The default system email sender is expected to be <code>kijo@work.amiosh.com</code>.</li>
    <li>The quotation email sender is expected to be <code>info.admin@amiosh.com</code>.</li>
    <li>The quote PDF diagnostic attaches <code>quote-mail-diagnostic.pdf</code>.</li>
    <li>When the signed-in staff email is available, the quote PDF diagnostic sets Reply-To to that staff email and name.</li>
</ul>

<h3>Diagnostic statuses</h3>
<ul>
    <li><strong>sending</strong> means the UI is waiting for the backend response.</li>
    <li><strong>sent</strong> means the mailer accepted the test send.</li>
    <li><strong>blocked</strong> means configuration is not live-ready or does not match expected sender rules.</li>
    <li><strong>failed</strong> means the mail send attempted but hit an exception or delivery pipeline error.</li>
</ul>

<h3>Why a diagnostic can be blocked</h3>
<ul>
    <li>The configured from address does not match the expected sender.</li>
    <li>The mailer uses a non-live transport such as array or log outside tests.</li>
    <li>The sender is blank or still uses an example.com address.</li>
    <li>SMTP host, port, username, or password is missing.</li>
</ul>

<p><strong>Important:</strong> Mail diagnostics are System Admin only. If a diagnostic is blocked, fix the mail configuration before retrying; repeated sends will not work until configuration is live-ready.</p>
```

## Article 13 - How to Manage Invoice Payment Status and Receipt PDFs

- Title: How to Manage Invoice Payment Status and Receipt PDFs
- Slug: `how-to-manage-invoice-payment-status-and-receipt-pdfs`
- Category: `Commercial`
- Related route: `/commercial/invoice`
- Tags: `invoice`, `paid`, `pending`, `receipt`, `pdf`, `commercial`
- Summary: Learn how to mark invoices paid, move paid invoices back to pending, generate invoice PDFs, and generate receipt PDFs only when payment data is valid.
- Evidence: `frontend/src/views/commercial/invoice/InvoiceDetailPage.jsx`, `frontend/src/views/commercial/invoice/InvoiceModal/MarkPaidModal.jsx`, `backend-laravel/routes/api.php`, `backend-laravel/app/Services/Invoices/InvoicePaymentService.php`, `backend-laravel/app/Services/Invoices/InvoicePdfService.php`

```html
<h3>Mark an invoice as paid</h3>
<ol>
    <li>Open <strong>Commercial Invoice</strong>.</li>
    <li>Open the invoice list or invoice detail page.</li>
    <li>For an invoice whose status is not exactly <strong>Paid</strong>, click <strong>Mark as Paid</strong>. The frontend exposes this action based on status; the backend mark-paid endpoint validates payment fields but does not separately reject cancelled or void statuses.</li>
    <li>Enter the payment date.</li>
    <li>Enter the paid amount. The amount must be greater than zero.</li>
    <li>Add payment remarks if needed.</li>
    <li>Confirm the action.</li>
</ol>

<h3>What changes after paid status</h3>
<ul>
    <li>The invoice status changes to <strong>Paid</strong>.</li>
    <li>The paid date, paid amount, and paid remarks are saved.</li>
    <li>The invoice edit action is locked and shows that the invoice must be marked pending before editing.</li>
    <li>The <strong>PDF Receipt</strong> action becomes available.</li>
    <li>The <strong>Mark as Pending</strong> action becomes available.</li>
</ul>

<h3>Generate PDFs</h3>
<p><strong>PDF Invoice</strong> can be generated from the invoice action list. <strong>PDF Receipt</strong> is available only for paid invoices in the frontend, and the backend also verifies that the invoice is paid, has a paid date, and has a positive paid amount.</p>

<h3>Receipt number behavior</h3>
<p>Receipt numbers are assigned when the receipt PDF is first generated if the invoice does not already have a receipt number. The generated receipt number is then kept on the invoice record.</p>

<h3>Move a paid invoice back to pending</h3>
<p>Use <strong>Mark as Pending</strong> when a paid invoice was marked incorrectly or needs correction. This clears the paid date, paid amount, and paid remarks, and changes the status back to <strong>Pending</strong>. It does not clear an existing receipt number, so if the invoice is paid again, receipt PDF generation reuses the existing receipt number unless it was empty.</p>

<h3>Common errors</h3>
<ul>
    <li>A route/body invoice ID mismatch returns a conflict error.</li>
    <li>A missing or invalid payment date is rejected.</li>
    <li>A paid amount of zero or less is rejected.</li>
    <li>A receipt PDF cannot be generated for an invoice that is not paid or does not have valid payment data.</li>
</ul>
```

## Article 14 - How to Manage Pipeline Inquiries and Bulk Pipeline Entries

- Title: How to Manage Pipeline Inquiries and Bulk Pipeline Entries
- Slug: `how-to-manage-pipeline-inquiries-and-bulk-pipeline-entries`
- Category: `CRM`
- Related route: `/pipeline/inquiries`
- Additional related route: `/pipeline/entries/bulk-add`
- Tags: `pipeline`, `inquiry`, `bulk-entry`, `lead`, `proof`, `crm`
- Summary: Learn how sales inquiries connect to clients and quotes, and how manual pipeline entries are bulk-added, validated, and protected.
- Evidence: `frontend/src/routes.js`, `frontend/src/views/marketing/inquiries/*`, `frontend/src/views/marketing/pipeline/*`, `backend-laravel/routes/api.php`, `backend-laravel/app/Services/Monitoring/ManualPipelineEntry*.php`, `backend-laravel/app/Services/Monitoring/ManualPipelineEntryBaseService.php`

```html
<h3>Use inquiries for sales leads</h3>
<p>Pipeline inquiries track prospect or client interest before it becomes a client, quote, or project. Inquiry records can include company and contact details, service, source, status, date, owner or PIC assignment, and proof images.</p>

<h3>Create and process an inquiry</h3>
<ol>
    <li>Open <strong>Pipeline Inquiries</strong>.</li>
    <li>Create a new inquiry with company, contact, service, source, status, and date information.</li>
    <li>Attach proof images if needed. Inquiry proof supports up to 10 screenshot proofs; the frontend compresses images toward 500 KB, and the backend accepts JPG, PNG, WebP, or GIF files up to 500 KB.</li>
    <li>Assign the inquiry owner or PIC.</li>
    <li>Open the inquiry detail page to view proof, link or create a client, create a quote, edit the inquiry, or delete it where permitted.</li>
</ol>

<h3>Use bulk pipeline entries for manual records</h3>
<ol>
    <li>Open <strong>Pipeline Records</strong>.</li>
    <li>Click <strong>Add Entries</strong> to open the bulk add page.</li>
    <li>Fill the row with type, date, source, classification, service category, estimated RM where allowed, company or prospect, notes, and optional screenshot proof.</li>
    <li>Click <strong>Add to Batch</strong>.</li>
    <li>Review the batch and save up to 100 entries at a time.</li>
</ol>

<h3>Manual entry types</h3>
<ul>
    <li><strong>Lead</strong></li>
    <li><strong>Qualified</strong></li>
    <li><strong>Meeting/Pitching</strong></li>
    <li><strong>Proposal</strong></li>
    <li><strong>Negotiation</strong></li>
    <li><strong>Closed</strong></li>
</ul>

<h3>Validation rules</h3>
<ul>
    <li>Each manual entry requires type, date, source, and prospect name.</li>
    <li>The frontend exposes Estimated RM for proposal and closed entries. The backend accepts numeric estimated values generally, but only closed entries require a service category and Estimated RM greater than zero.</li>
    <li>Bulk saves are capped at 100 entries.</li>
    <li>Uploaded screenshot proof is capped at 500 KB on the backend.</li>
    <li>File attachments are not restored from local draft storage after refresh.</li>
</ul>

<h3>Permissions and read-only records</h3>
<p>Managers, HR, admin, and super-like roles can view other staff manual entries. Non-management users cannot create, view, update, or delete entries for another staff member. Submitted free legal compliance assessments can be merged into pipeline records as read-only meeting or pitching entries when they have no linked project, are not deleted, and are not superseded. These records route to assessment review instead of manual edit or delete.</p>

<p><strong>Tip:</strong> Use inquiries when a lead still needs client or quote conversion. Use manual pipeline entries when you need direct pipeline monitoring records for leads, meetings, proposals, negotiations, or closed value.</p>
```

## Article 15 - How to Review Client ROI and Past PICs

- Title: How to Review Client ROI and Past PICs
- Slug: `how-to-review-client-roi-and-past-pics`
- Category: `CRM`
- Related route: `/client/roi`
- Tags: `client`, `roi`, `commercial-history`, `invoice`, `quote`, `past-pic`
- Summary: Learn how client ROI is calculated, how to read commercial history, and how unassigned past PIC records are managed.
- Evidence: `frontend/src/views/client/manage/roi/*`, `backend-laravel/routes/api.php`, `backend-laravel/app/Services/Clients/ClientRoiReportService.php`, `backend-laravel/app/Services/Clients/ClientCommercialHistoryService.php`, explorer findings for Past PICs

```html
<h3>Open Client ROI</h3>
<ol>
    <li>Open <strong>Client ROI</strong>.</li>
    <li>Select the period or date range to review.</li>
    <li>Search for a client or use profitability filters.</li>
    <li>Open the client detail or commercial history view for deeper review.</li>
</ol>

<h3>What the ROI page shows</h3>
<p>The ROI page summarizes client commercial performance for the selected period. The detail view includes summary values, payment history, invoice history, and quotation or award history. Detail actions can open related invoice, manual debtor, quote, or project records.</p>

<h3>Actual and projected ROI</h3>
<p>The ROI report distinguishes actual and projected performance. Actual ROI and margin use received totals, while projected ROI and margin use awarded value. Both ROI values return null when there is no cost base.</p>

<h3>How ROI data is scoped</h3>
<ul>
    <li>Terminated projects are excluded from aggregate ROI calculations.</li>
    <li>Cancelled, canceled, or void invoices are excluded from aggregate calculations.</li>
    <li>Commercial history can still show void invoice rows for audit visibility.</li>
    <li>Manual debtors count only when they are linked to the client.</li>
    <li>ROI percentages are null when there is no cost base.</li>
    <li>Payment days are ignored when the paid date is before the invoice date.</li>
</ul>

<h3>Date range rules</h3>
<p>Date filters must use valid <code>YYYY-MM-DD</code> dates, and the start date must be on or before the end date. If the date range is invalid, the backend rejects the request instead of returning misleading results.</p>

<h3>Past PICs</h3>
<p>The client PIC endpoint returns non-deleted PIC records. The <strong>Past PICs</strong> page filters that list to records whose status is <strong>unassigned</strong>. Authorized users can delete an unassigned past PIC after confirmation, and the backend only soft-deletes records whose status is unassigned.</p>

<p><strong>Tip:</strong> Use ROI for client performance review, then open commercial history when you need the supporting invoices, payment rows, quote awards, or project links behind the summary.</p>
```

## Article 16 - How Knowledge Hub Authoring, Publishing, Archiving, and Assistant Use Work

- Title: How Knowledge Hub Authoring, Publishing, Archiving, and Assistant Use Work
- Slug: `how-knowledge-hub-authoring-publishing-archiving-and-assistant-use-work`
- Category: `System`
- Related route: `/knowledge`
- Tags: `knowledge`, `article`, `publish`, `archive`, `assistant`, `draft`
- Summary: Learn how Knowledge Hub articles are created, published, unpublished, archived, searched, and used by the assistant.
- Evidence: `frontend/src/views/knowledge/KnowledgeHub.jsx`, `frontend/src/views/knowledge/KnowledgeArticleForm.jsx`, `frontend/src/views/knowledge/form/useKnowledgeArticleForm.js`, `frontend/src/views/knowledge/side-panel/*`, `backend-laravel/routes/api.php`, `backend-laravel/app/Services/Knowledge/KnowledgeService.php`, `backend-laravel/app/Services/Knowledge/KnowledgeAssistantService.php`

```html
<h3>Browse and search articles</h3>
<p>Open <strong>Knowledge Hub</strong> to search and filter articles. The public article index returns published articles without full body HTML, while authenticated Knowledge Hub workspaces can load drafts and archived records through the management views.</p>

<h3>Create an article</h3>
<ol>
    <li>Open <strong>Knowledge Hub</strong>.</li>
    <li>Click <strong>Create Article</strong>.</li>
    <li>Enter title, category, summary, tags, and related page route.</li>
    <li>Write the article body.</li>
    <li>Add screenshots if helpful, and provide a description for every image.</li>
    <li>Save as draft or publish.</li>
</ol>

<h3>Edit an article</h3>
<p>Editing an existing article requires edit remarks. Updates cannot directly change lifecycle status. Use the dedicated publish, unpublish, and archive actions for lifecycle changes.</p>

<h3>Publishing and archiving rules</h3>
<ul>
    <li>Draft articles can be published.</li>
    <li>Published articles can be unpublished.</li>
    <li>Articles can be archived when they should no longer be used.</li>
    <li>Archived articles cannot be edited, restored, or published through the current article workflow.</li>
    <li>Related routes must start with <code>/</code>.</li>
    <li>Attach up to 10 images per article.</li>
    <li>Images must be JPG, JPEG, PNG, or WebP files up to 5 MB each.</li>
    <li>Descriptions are required for both new and retained images.</li>
</ul>

<p><strong>Route note:</strong> Keep <code>/knowledge</code> as the article browsing route, but remember that Knowledge routes are intentionally excluded from assistant inline route links.</p>

<h3>Assistant side panel</h3>
<p>The Knowledge assistant side panel supports article search, AI chat, source links, related-page navigation, thread history, and helpful or bad feedback. The assistant is read-only and source-grounded. It can fall back when AI is unavailable, unconfigured, low-confidence, or lacks enough sources.</p>

<h3>Assistant data limits</h3>
<ul>
    <li>Chat threads are trimmed to recent messages.</li>
    <li>Assistant chat data expires after the configured retention period.</li>
    <li>Feedback is recorded for governance review.</li>
    <li>The assistant should not perform write actions; it points users to the correct page and source instead.</li>
</ul>

<p><strong>Important:</strong> Seeded backend articles are preferred for stable system instructions. Use the frontend form for normal authoring, but migrate durable operational articles into backend seed or migration files when they should exist in every environment.</p>
```

## Article 17 - How to Use Staff Activity Logs and Exported Activity Reports

- Title: How to Use Staff Activity Logs and Exported Activity Reports
- Slug: `how-to-use-staff-activity-logs-and-exported-activity-reports`
- Category: `System`
- Related route: `/staff/activities`
- Tags: `staff`, `activity`, `audit`, `report`, `export`, `hr`
- Summary: Learn how HR and system admins filter activity logs, interpret activity stats, export table data, and generate PDF activity reports.
- Evidence: `frontend/src/views/staff/activities/*`, `backend-laravel/routes/api.php`, `backend-laravel/app/Http/Requests/Staff/ListActivityRequest.php`, `backend-laravel/app/Http/Requests/Staff/GenerateUserActivityReportRequest.php`, `backend-laravel/app/Services/Staff/StaffActivityService.php`

```html
<h3>Who can use activity logs</h3>
<p>Staff activity logs are intended for HR and System Admin users. The frontend route may appear in manager-level navigation, but the backend activity list and report authorization allows only exact <strong>System Admin</strong> or <strong>HR</strong>; Manager users receive a backend authorization error.</p>

<h3>Review activity logs</h3>
<ol>
    <li>Open <strong>Staff Activities</strong>.</li>
    <li>Select the period you want to review.</li>
    <li>Filter by user code if you need one staff member's activity.</li>
    <li>Use keyword search to find a specific action or record context.</li>
    <li>Review the table and summary stats.</li>
</ol>

<h3>Activity stats</h3>
<p>The page summarizes activity count, active users, top user, and top action. Use the stats as a quick guide, then use the table for record-level review.</p>

<h3>Table export</h3>
<p>The activity table uses the shared record list export behavior. Use the table export when you need a CSV-style extract of the currently loaded data.</p>

<h3>Generate a PDF report</h3>
<ol>
    <li>Click <strong>Export Report</strong>.</li>
    <li>Choose the keyword, user, period, custom range, or month options needed for the report. The export modal uses its own filters and defaults to <strong>Last 1 Year</strong>; it does not automatically inherit the table's current search, user, or period filters.</li>
    <li>Generate the report.</li>
    <li>The backend returns an inline PDF named <code>user_activity_log.pdf</code>.</li>
</ol>

<h3>Limits and pagination</h3>
<ul>
    <li>The activity list is paginated by the backend.</li>
    <li>The frontend loader fetches all pages for the selected period.</li>
    <li>The backend caps list page size at 500 rows per page.</li>
    <li>The PDF report is capped at 5,000 rows.</li>
</ul>

<p><strong>Tip:</strong> Use filters before generating a report. Narrow reports are easier to review and less likely to hit the PDF row cap.</p>
```

## Article 18 - How Digital Signature and Password Self-Service Work

- Title: How Digital Signature and Password Self-Service Work
- Slug: `how-digital-signature-and-password-self-service-work`
- Category: `Getting Started`
- Related route: `/my/signature`
- Tags: `signature`, `password`, `account`, `self-service`, `reset-password`, `profile`
- Summary: Learn how staff upload their personal signature, update their password, and use password reset safely.
- Evidence: `frontend/src/components/signature/PersonalSignature.js`, `frontend/src/views/account/AccountWorkspace` via explorer findings, `frontend/src/views/pages/login/PasswordReset.js`, `backend-laravel/routes/api.php`, `backend-laravel/app/Http/Controllers/Api/SignatureController.php`, `backend-laravel/app/Http/Controllers/Api/AuthController.php`, `backend-laravel/app/Http/Requests/Signature/StoreSignatureRequest.php`

```html
<h3>Account self-service pages</h3>
<p>The account workspace includes profile, signature, and password self-service pages. Signature is managed at <strong>My Signature</strong>, password update is managed at <strong>My Password</strong>, and public password reset is available through the reset-password link flow.</p>

<h3>Upload or replace your signature</h3>
<ol>
    <li>Open <strong>My Signature</strong>.</li>
    <li>Review the current signature if one already exists.</li>
    <li>Select a JPEG or PNG image up to 2 MB.</li>
    <li>Preview the selected file.</li>
    <li>If replacing an existing signature, confirm the replacement.</li>
    <li>Upload the signature.</li>
</ol>

<h3>How signature files are stored</h3>
<p>The signature filename is based on the current staff ID and name code. When a new signature is saved, older JPG or PNG variants for that current staff signature are removed before saving the new file. If a staff member's name code changes, files using the previous name code are not discovered or removed by this self-service flow. After upload, the page dispatches a signature-updated event so the header warning can refresh.</p>

<h3>Signature missing warning</h3>
<p>The app header checks the signature endpoint and can show a <strong>Signature missing</strong> warning with an upload action. Uploading a signature clears the warning after the signature state refreshes.</p>

<h3>Update your password</h3>
<ol>
    <li>Open <strong>My Password</strong>.</li>
    <li>Enter your current password.</li>
    <li>Enter a new password between 12 and 128 characters.</li>
    <li>Confirm the new password.</li>
    <li>Submit the update.</li>
</ol>

<h3>Password reset</h3>
<p>If you cannot sign in, use forgot password. The reset request does not reveal whether an email exists. When valid, the system sends a tokenized reset link that expires after 60 minutes. The reset form validates email, token, new password, and confirmation.</p>

<h3>Security behavior</h3>
<ul>
    <li>Wrong current password is rejected.</li>
    <li>Short, long, or mismatched passwords are rejected.</li>
    <li>Successful password update changes the backend password, invalidates other sessions and remember tokens, then the frontend calls logout after a success delay.</li>
    <li>Password reset consumes the token and invalidates active sessions.</li>
</ul>
```

## Article 19 - How to Troubleshoot Auth, Session, and Role Access Issues

- Title: How to Troubleshoot Auth, Session, and Role Access Issues
- Slug: `how-to-troubleshoot-auth-session-and-role-access-issues`
- Category: `System`
- Related route: `/login`
- Tags: `auth`, `login`, `session`, `role`, `remember-me`, `csrf`, `troubleshooting`
- Summary: Learn how login, session checks, role gates, remember-me restore, password changes, and session expiry behave in the system.
- Evidence: `frontend/src/auth/AuthProvider.jsx`, `frontend/src/ProtectedRoute.js`, `frontend/src/utils/roles.js`, `backend-laravel/routes/api.php`, `backend-laravel/app/Http/Controllers/Api/AuthController.php`, `backend-laravel/app/Http/Middleware/RequireAuth.php`, `backend-laravel/app/Http/Middleware/RequireRole.php`

```html
<h3>How protected pages check login</h3>
<p>The frontend auth provider checks the backend session endpoint on protected pages and repeats the check periodically. If the session is missing or expired, the app redirects the user back to the login page. Protected routes also redirect unauthenticated users to login.</p>

<h3>Normal login flow</h3>
<ol>
    <li>Open the login page.</li>
    <li>Enter email and password.</li>
    <li>Optionally choose remember me for a longer restore window.</li>
    <li>After login, the backend session and role data control access to protected pages.</li>
</ol>

<h3>Role access behavior</h3>
<ul>
    <li>Frontend protected routes redirect role failures back to the dashboard.</li>
    <li>Backend role middleware enforces the real permission check.</li>
    <li>System Admin can pass explicit backend role gates where the middleware allows the system-admin bypass.</li>
    <li>If a user's role is downgraded in the database, stale admin sessions can be rejected on later checks.</li>
</ul>

<h3>Common auth states</h3>
<ul>
    <li><strong>Invalid credentials</strong> means the email or password is wrong.</li>
    <li><strong>Temporary or permanent lock</strong> can occur after account security rules are triggered.</li>
    <li><strong>Rate limit</strong> means too many requests were made in a short period.</li>
    <li><strong>Session expired</strong> means the user must sign in again.</li>
    <li><strong>CSRF mismatch</strong> can occur on unsafe requests when the browser session token no longer matches the backend.</li>
</ul>

<h3>Why a session can be rejected</h3>
<ul>
    <li>The system user is inactive.</li>
    <li>The account is locked.</li>
    <li>The session staff ID no longer matches the system user.</li>
    <li>The user's current database role no longer allows the attempted page or action.</li>
    <li>The remember-me token is missing, expired, mismatched, cleared by logout, or cleared by password change.</li>
</ul>

<h3>Password and remember-me effects</h3>
<p>Successful password update invalidates other sessions and remember tokens. Remember-me restore rotates the remember cookie on a valid restore and clears it when the token is missing, expired, mismatched, cleared by logout, or cleared by password change.</p>

<p><strong>Tip:</strong> When a user reports sudden access loss, check whether their account is active, whether the staff ID still matches, whether their role changed, and whether the backend returned an auth error rather than a frontend-only route issue.</p>
```

## Article 20 - How to Read the System Admin Schema and Migration Status Page

- Title: How to Read the System Admin Schema and Migration Status Page
- Slug: `how-to-read-the-system-admin-schema-and-migration-status-page`
- Category: `System`
- Related route: `/system-admin/dashboard`
- Tags: `migration`, `schema`, `system-admin`, `deployment`, `laravel`, `read-only`
- Summary: Learn how the read-only migration status page reports pending migrations, applied migrations, missing files, archived migrations, and why migrations must run outside the browser.
- Evidence: `frontend/src/views/system-admin/SystemAdminDashboard.jsx`, `frontend/src/views/system-admin/schema-sync/SchemaScriptsTable.jsx`, `frontend/src/views/system-admin/schema-sync/schemaSyncUtils.js`, `backend-laravel/routes/api.php`, `backend-laravel/app/Http/Controllers/Api/AdminController.php`, `backend-laravel/database/schema-sync/README.md`, `backend-laravel/app/Console/Commands/AuditSchemaDrift.php`

```html
<h3>What the migration status page shows</h3>
<p>The System Admin Dashboard opens to a read-only <strong>Laravel Migration Status</strong> page. It compares migration files in the codebase with rows recorded in the database migrations table.</p>

<h3>Summary tiles</h3>
<ul>
    <li><strong>Pending Migrations</strong> counts migration files that exist in the codebase but are not applied in the database.</li>
    <li><strong>Applied</strong> counts known migrations already applied.</li>
    <li><strong>Missing Files</strong> counts applied migration names that no longer have a matching file and are not intentionally archived.</li>
    <li><strong>Total Known</strong> counts the combined known migration set from files and database rows.</li>
</ul>

<h3>Read the table</h3>
<p>The table classifies each migration file as present, missing file, archived, applied, pending, synced, or not applied depending on the file and database state. Use the table to identify whether the database matches the migration files currently deployed with the codebase.</p>

<h3>Why the page is read-only</h3>
<p>Browser-run schema sync is disabled. The UI explicitly instructs admins to run migrations through deployment or the server terminal using <code>php artisan migrate</code>. The backend run-migrations endpoint returns a disabled response instead of running migrations from the browser.</p>

<h3>Access and safety behavior</h3>
<ul>
    <li>Only System Admin users can view migration status.</li>
    <li>Non-admin users receive an authorization error.</li>
    <li>Inactive or stale sessions can be invalidated.</li>
    <li>Archived historical Laravel migrations are not counted as missing files.</li>
    <li>Sensitive environment and database details are intentionally omitted from the response.</li>
</ul>

<h3>Schema drift audit command</h3>
<p>The separate CLI command <code>app:audit-schema-drift</code> checks backend code references against live schema assumptions. It can help developers find missing columns, omitted required insert columns, and silent database error patterns. This is a CLI diagnostic command, not a browser action.</p>

<p><strong>Important:</strong> If pending migrations appear, coordinate with the deployment or server operator. Do not try to revive legacy browser schema sync scripts; new database changes must use Laravel migrations.</p>
```
