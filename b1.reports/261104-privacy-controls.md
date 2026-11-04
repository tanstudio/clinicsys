# Hong Kong privacy controls and release evidence

Scope: the local Clinic review build and synthetic patient and employee records.
Reference: Hong Kong Privacy Commissioner, [The Six Data Protection Principles](https://www.pcpd.org.hk/english/news_events/whatison/files/Points_to_Note_for_Senior_Management.pdf).
The following review distinguishes implemented controls from decisions that require the clinic operator and its privacy adviser.

| Principle | Local control | Required before live use |
| --- | --- | --- |
| Purpose and collection | The website requests a name and Hong Kong telephone number for a booking and makes the email field optional. | Approve the identity of the data user, collection purposes, mandatory fields, recipient classes and privacy contact displayed to patients and employees. |
| Accuracy and retention | Appointment changes keep an event history and a stable patient reference. | Set record-specific retention and correction procedures for clinical, employee, messaging, backup and access records. |
| Use | Operational messaging and promotional subscription are separate decisions. | Approve marketing consent wording and evidence and check the configured provider workflows against the approved purposes. |
| Security | Staff sessions and role checks protect the portal. Sensitive patient fields, clinical records and HR attachments use application encryption. Staff access logging records route patterns and outcomes without storing form bodies. | Test backup recovery, encryption key custody, TLS, credential revocation, privileged access reviews and incident response in the real environment. |
| Transparency | A bilingual review-build collection notice is linked from patient intake. | Publish the approved clinic policy and processor information using the real organisation and contact details. |
| Access and correction | Staff can locate branch-scoped patient records and review linked appointment and encounter history. | Assign an owner to identify applicants, record requests, provide responses and document corrections. |

A direct database or browser test cannot establish organisation-wide legal compliance.
The clinic operator must approve the real deployment, operational controls and notices before production patient data is collected.
