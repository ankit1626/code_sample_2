### **Requirements**

The client’s business focused on selling gas canisters using the WooCommerce plugin. They wanted to provide end users the option to return empty gas canisters, with an additional fee applied if the return was not completed. Since the business involved physical product sales, shipping played a critical role. A key requirement for the shipping partner was the ability to provide a "pay-on-use" return label. Additionally, the client requested that service levels and carrier options be managed exclusively by the admin team rather than the end users.

---

### **Approach**

Based on the requirements, the following modules were necessary:
- A module to differentiate between returnable and non-returnable products.
- A module to integrate with the shipping partner for generating shipping labels and tracking their status.
- A module to apply additional fees for unreturned items.
- A module to enable bulk generation of shipping labels.

---

### **Module for Differentiating Between Returnable and Non-Returnable Products**

This module was designed to help the system identify whether a product was returnable or non-returnable. To achieve this, the WooCommerce shipping class feature was utilized, allowing easy categorization of products based on their returnability.

---

### **Module for Shipping Label Generation and Tracking**

This module enabled the generation of shipping labels by incorporating entities such as order details, shipping class, and the shipping partner. Integration was achieved through the REST APIs provided by the shipping services, and webhooks were implemented to monitor and update the tracking status in real time.

---

### **Module for Applying Additional Fees**

This module ensured that additional fees were charged if customers failed to return the product within a specified timeframe, effectively automating the fee application process.

---

### **Module for Bulk Shipping Label Generation**

As an extension of the shipping label generation module, this feature allowed the admin team to generate shipping labels in bulk. It also facilitated the categorization of labels based on product types, streamlining the shipping process.