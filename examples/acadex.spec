school table:schools softDeletes:
- schoolName string:150 required
- contactPerson string:120 required
- contactEmail string:191 required unique
- contactPhone string:30 required unique
- logoPath string:255 nullable
- isActive boolean default:true index

guardian table:guardians softDeletes:
- schoolId foreignId:schools required cascadeOnDelete
- fullName string:150 required
- email string:191 nullable index
- phone string:30 required index
- whatsappPhone string:30 nullable
- subscriptionStatus enum:inactive,active,past_due default:inactive index

student table:students softDeletes:
- schoolId foreignId:schools required cascadeOnDelete
- guardianId foreignId:guardians nullable nullOnDelete
- admissionNumber string:60 required unique
- firstName string:100 required
- lastName string:100 required
- middleName string:100 nullable
- gender enum:male,female nullable
- dateOfBirth date nullable
- passportPath string:255 nullable

score table:scores:
- schoolId foreignId:schools required cascadeOnDelete
- studentId foreignId:students required cascadeOnDelete
- subjectId foreignId:subjects required restrictOnDelete
- schoolSessionId foreignId:school_sessions required restrictOnDelete
- schoolTermId foreignId:school_terms required restrictOnDelete
- cat decimal:5,2 default:0
- exam decimal:5,2 default:0
- total decimal:5,2 default:0
- grade string:10 nullable
- remark string:120 nullable
- isAmended boolean default:false index

payment table:payments:
- schoolId foreignId:schools required cascadeOnDelete
- guardianId foreignId:guardians required cascadeOnDelete
- provider string:40 required
- providerReference string:120 required unique
- amount decimal:12,2 required
- currency string:3 default:NGN
- status enum:pending,paid,failed,refunded default:pending index
- paidAt datetime nullable
