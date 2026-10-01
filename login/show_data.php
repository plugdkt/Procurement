POST /mobile/StaffService.asmx HTTP/1.1
Host: ws.up.ac.th
Content-Type: application/soap+xml; charset=utf-8
Content-Length: length

<?xml version="1.0" encoding="utf-8"?>
<soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">
  <soap12:Body>
    <GetStaffInfo xmlns="http://tempuri.org/">
      <sessionID>string</sessionID>
    </GetStaffInfo>
  </soap12:Body>
</soap12:Envelope>

HTTP/1.1 200 OK
Content-Type: application/soap+xml; charset=utf-8
Content-Length: length

<?xml version="1.0" encoding="utf-8"?>
<soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">
  <soap12:Body>
    <GetStaffInfoResponse xmlns="http://tempuri.org/">
      <GetStaffInfoResult>
        <Title>string</Title>
        <FirstName_TH>string</FirstName_TH>
        <LastName_TH>string</LastName_TH>
        <CitizenID>string</CitizenID>
        <Department>string</Department>
        <Faculty>string</Faculty>
        <Status>string</Status>
        <GroupType>string</GroupType>
      </GetStaffInfoResult>
    </GetStaffInfoResponse>
  </soap12:Body>
</soap12:Envelope>

