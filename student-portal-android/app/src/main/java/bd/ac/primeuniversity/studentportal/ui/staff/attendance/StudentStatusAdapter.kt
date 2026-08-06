package bd.ac.primeuniversity.studentportal.ui.staff.attendance

import android.graphics.Color
import android.view.LayoutInflater
import android.view.ViewGroup
import android.widget.TextView
import androidx.core.content.ContextCompat
import androidx.core.graphics.ColorUtils
import androidx.recyclerview.widget.RecyclerView
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.AttStudent
import bd.ac.primeuniversity.studentportal.databinding.ItemAttStudentBinding

/**
 * Student roster with a P/A/L/E status chip group per row. Every student
 * defaults to Present (or the previously saved status when editing a date).
 */
class StudentStatusAdapter : RecyclerView.Adapter<StudentStatusAdapter.Holder>() {

    private val students = mutableListOf<AttStudent>()
    private val statuses = mutableMapOf<Int, String>()

    fun submit(list: List<AttStudent>, existing: Map<String, String>) {
        students.clear()
        students.addAll(list)
        statuses.clear()
        list.forEach { statuses[it.id] = existing[it.id.toString()] ?: STATUS_PRESENT }
        notifyDataSetChanged()
    }

    fun markAll(status: String) {
        students.forEach { statuses[it.id] = status }
        notifyDataSetChanged()
    }

    /** Current [student PK → status] selections. */
    fun snapshot(): Map<Int, String> = statuses.toMap()

    override fun getItemCount(): Int = students.size

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): Holder =
        Holder(ItemAttStudentBinding.inflate(LayoutInflater.from(parent.context), parent, false))

    override fun onBindViewHolder(holder: Holder, position: Int) =
        holder.bind(students[position], position)

    inner class Holder(private val binding: ItemAttStudentBinding) :
        RecyclerView.ViewHolder(binding.root) {

        fun bind(student: AttStudent, index: Int) {
            binding.rowIndex.text = (index + 1).toString()
            binding.studentName.text = student.fullName ?: ""
            binding.studentMeta.text = listOfNotNull(
                student.studentId?.takeIf { it.isNotBlank() },
                student.section?.takeIf { it.isNotBlank() }?.let { "Sec $it" },
            ).joinToString(" \u00b7 ")

            val chips: List<Pair<TextView, Pair<String, Int>>> = listOf(
                binding.chipPresent to (STATUS_PRESENT to R.color.success),
                binding.chipAbsent to (STATUS_ABSENT to R.color.error),
                binding.chipLate to (STATUS_LATE to R.color.warning),
                binding.chipExcused to (STATUS_EXCUSED to R.color.info),
            )
            chips.forEach { (chip, meta) ->
                val (key, colorRes) = meta
                val color = ContextCompat.getColor(binding.root.context, colorRes)
                val selected = statuses[student.id] == key
                chip.background?.mutate()?.setTint(
                    if (selected) color else ColorUtils.setAlphaComponent(color, 31)
                )
                chip.setTextColor(if (selected) Color.WHITE else color)
                chip.setOnClickListener {
                    statuses[student.id] = key
                    notifyItemChanged(bindingAdapterPosition)
                }
            }
        }
    }

    companion object {
        const val STATUS_PRESENT = "present"
        const val STATUS_ABSENT = "absent"
        const val STATUS_LATE = "late"
        const val STATUS_EXCUSED = "excused"
    }
}
