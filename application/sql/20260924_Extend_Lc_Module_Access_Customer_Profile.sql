-- Extend the Leads/Customer access grid to cover the Customer Profile page
-- (Action ▸ Customer Profile → Customer_Analysis). It used to be owner-only;
-- now it is owner-granted per admin like the other modules (owner implicit
-- full access, everyone else grant-only).
ALTER TABLE `lc_module_access`
  MODIFY `Module`
    ENUM('customer','guests','ghl_leads','manual_leads','campaign','lead_status','nature_of_business','customer_profile')
    NOT NULL;
