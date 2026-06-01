@app architecture:modular

module Academics:
student table:students:
@search firstName lastName status
- status enum:Active,Inactive default:Active index
- firstName string:100 required
- lastName string:100 required

module Billing:
payment table:payments:
@upload field:file mimes:csv,xls,xlsx max:4096
- reference string:80 required unique
- amount decimal:12,2 required
- status enum:pending,paid,failed default:pending index
