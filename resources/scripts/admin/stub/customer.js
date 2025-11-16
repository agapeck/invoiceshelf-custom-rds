import addressStub from '@/scripts/admin/stub/address.js'

export default function () {
  return {
    name: '',
    contact_name: '',
    email: '',
    phone: null,
    password: '',
    confirm_password:'',
    currency_id: null,
    website: null,
    // Patient/Medical fields
    age: null,
    next_of_kin: '',
    next_of_kin_phone: '',
    diagnosis: '',
    treatment: '',
    attended_to_by: '',
    review_date: '',
    billing: { ...addressStub },
    shipping: { ...addressStub },
    customFields: [],
    fields: [],
    enable_portal: false,
  }
}
